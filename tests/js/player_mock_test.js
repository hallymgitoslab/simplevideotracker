// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

/**
 * Lightweight browser-state regression tests for the compiled player AMD module.
 *
 * Run with: node tests/js/player_mock_test.js
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class FakeVideo {
    constructor({duration = 100, readyState = 1} = {}) {
        this.duration = duration;
        this.readyState = readyState;
        this.currentTime = 0;
        this.paused = true;
        this.ended = false;
        this.seeking = false;
        this.playbackRate = 1;
        this.listeners = {};
    }

    addEventListener(name, callback) {
        this.listeners[name] = this.listeners[name] || [];
        this.listeners[name].push(callback);
    }

    emit(name) {
        (this.listeners[name] || []).forEach((callback) => callback());
    }
}

const flushPromises = async() => {
    // Promise jobs can cross the host/vm boundary in this harness; drain several turns.
    for (let index = 0; index < 5; index++) {
        await new Promise((resolve) => setImmediate(resolve));
        await Promise.resolve();
    }
};

const loadPlayer = (video, results, {deferredAjax = false} = {}) => {
    const requests = [];
    const timeouts = [];
    const intervals = [];
    let now = 0;
    const status = {textContent: ''};
    const progress = {textContent: ''};
    const ajax = {
        call(calls) {
            requests.push(calls[0].args);
            const result = results.shift();
            const promise = result instanceof Error ? Promise.reject(result) : Promise.resolve(result);
            if (!deferredAjax) {
                return [promise];
            }

            // Deliberately expose only a Deferred-style thenable: no native catch/finally.
            // Promise.resolve() in the player must assimilate this safely.
            return [{
                then(onFulfilled, onRejected) {
                    promise.then(onFulfilled, onRejected);
                    return this;
                },
                done(onFulfilled) {
                    promise.then(onFulfilled);
                    return this;
                },
                fail(onRejected) {
                    promise.catch(onRejected);
                    return this;
                },
            }];
        },
    };
    const sandbox = {
        define(name, dependencies, factory) {
            const exports = {};
            factory(exports, ajax);
            sandbox.player = exports;
        },
        document: {
            getElementById(id) {
                if (id === 'player') {
                    return video;
                }
                return id === 'status' ? status : progress;
            },
        },
        window: {
            setInterval(callback) {
                intervals.push(callback);
                return intervals.length;
            },
            setTimeout(callback) {
                timeouts.push(callback);
                return timeouts.length;
            },
            clearTimeout() {},
        },
        performance: {now: () => now},
        console,
    };

    vm.createContext(sandbox);
    const playerpath = path.resolve(__dirname, '../../amd/build/player.min.js');
    vm.runInContext(fs.readFileSync(playerpath, 'utf8'), sandbox);

    const config = {
        playerId: 'player',
        statusId: 'status',
        progressId: 'progress',
        cmid: 1,
        initialPosition: 0,
        allowedUntil: 0,
        preventSeeking: false,
        seekTolerance: 3,
        heartbeat: 5,
        strings: {
            progress: 'Progress',
            saving: 'Saving',
            saved: 'Saved',
            savefailed: 'Failed',
            savepartial: 'Partial',
            savepending: 'Pending',
            seekblocked: 'Blocked',
        },
    };

    return {
        player: sandbox.player,
        config,
        requests,
        timeouts,
        intervals,
        setNow(value) {
            now = value;
        },
    };
};

const run = async() => {
    // Resume must work even if loadedmetadata fired before init().
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, []);
        harness.config.initialPosition = 45;
        harness.player.init(harness.config);
        assert.strictEqual(video.currentTime, 45);
    }

    // A Deferred-style core/ajax thenable without catch/finally must still release saving
    // and schedule the queued retry after a rejected heartbeat.
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, [
            {accepted: false, reason: 'no_elapsed_time', creditedfrom: 0, creditedto: 0, alloweduntil: 0, progress: 0},
            {accepted: true, reason: 'ok', creditedfrom: 0, creditedto: 1, alloweduntil: 1, progress: 1},
        ], {deferredAjax: true});
        harness.player.init(harness.config);

        video.paused = false;
        video.emit('play');
        harness.setNow(1000);
        video.currentTime = 1;
        video.emit('timeupdate');
        video.paused = true;
        video.emit('pause');
        await flushPromises();

        assert.strictEqual(harness.requests.length, 1);
        assert.ok(harness.timeouts.length >= 1);
        harness.timeouts[harness.timeouts.length - 1]();
        await flushPromises();
        assert.strictEqual(harness.requests.length, 2);
        assert.strictEqual(harness.requests[1].from, 0);
        assert.strictEqual(harness.requests[1].to, 1);
    }

    // An allowed seek must preserve the natural range watched before the seek target was selected.
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, [{
            accepted: true,
            reason: 'ok',
            creditedfrom: 0,
            creditedto: 4,
            alloweduntil: 4,
            progress: 4,
        }]);
        harness.player.init(harness.config);
        video.paused = false;
        video.emit('play');
        harness.setNow(4000);
        video.currentTime = 4;
        video.emit('timeupdate');
        video.seeking = true;
        video.currentTime = 50;
        video.emit('seeking');
        await flushPromises();
        assert.strictEqual(harness.requests[0].from, 0);
        assert.strictEqual(harness.requests[0].to, 4);
    }

    // A rejected range remains queued and is retransmitted without collapsing to a zero-length range.
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, [
            {accepted: true, reason: 'ok', creditedfrom: 0, creditedto: 5, alloweduntil: 5, progress: 5},
            {accepted: false, reason: 'no_elapsed_time', creditedfrom: 0, creditedto: 0, alloweduntil: 5, progress: 5},
            {accepted: true, reason: 'ok', creditedfrom: 5, creditedto: 5.8, alloweduntil: 5.8, progress: 5.8},
        ]);
        harness.player.init(harness.config);
        video.paused = false;
        video.emit('play');
        harness.setNow(5000);
        video.currentTime = 5;
        video.emit('timeupdate');
        video.paused = true;
        video.emit('pause');
        await flushPromises();

        video.paused = false;
        video.emit('play');
        harness.setNow(5800);
        video.currentTime = 5.8;
        video.emit('timeupdate');
        video.paused = true;
        video.emit('pause');
        await flushPromises();
        assert.strictEqual(harness.requests[1].from, 5);
        assert.strictEqual(harness.requests[1].to, 5.8);

        harness.timeouts[harness.timeouts.length - 1]();
        await flushPromises();
        assert.strictEqual(harness.requests[2].from, 5);
        assert.strictEqual(harness.requests[2].to, 5.8);
    }

    // Partial credit retries only the remainder, not the full original interval.
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, [
            {
                accepted: false,
                reason: 'capped_to_elapsed_time',
                creditedfrom: 0,
                creditedto: 5.4,
                alloweduntil: 5.4,
                progress: 5.4,
            },
            {accepted: true, reason: 'ok', creditedfrom: 5.4, creditedto: 20, alloweduntil: 20, progress: 20},
        ]);
        harness.player.init(harness.config);
        video.paused = false;
        video.emit('play');
        harness.setNow(20000);
        video.currentTime = 20;
        video.emit('timeupdate');
        video.paused = true;
        video.emit('pause');
        await flushPromises();

        harness.timeouts[harness.timeouts.length - 1]();
        await flushPromises();
        assert.strictEqual(harness.requests[1].from, 5.4);
        assert.strictEqual(harness.requests[1].to, 20);
    }

    // Storage completion uses a much tighter epsilon than playback event coalescing.
    // A 0.04 second uncredited tail must remain queued and be retried.
    {
        const video = new FakeVideo({readyState: 1});
        const harness = loadPlayer(video, [
            {
                accepted: false,
                reason: 'capped_to_elapsed_time',
                creditedfrom: 0,
                creditedto: 10.8,
                alloweduntil: 10.8,
                progress: 10.8,
            },
            {accepted: true, reason: 'ok', creditedfrom: 10.8, creditedto: 10.84, alloweduntil: 10.84, progress: 10.84},
        ]);
        harness.player.init(harness.config);
        video.paused = false;
        video.emit('play');
        harness.setNow(10840);
        video.currentTime = 10.84;
        video.emit('timeupdate');
        video.paused = true;
        video.emit('pause');
        await flushPromises();

        harness.timeouts[harness.timeouts.length - 1]();
        await flushPromises();
        assert.strictEqual(harness.requests[1].from, 10.8);
        assert.strictEqual(harness.requests[1].to, 10.84);
    }

    console.log('Simple Video Tracker player mock regression tests: PASS');
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
