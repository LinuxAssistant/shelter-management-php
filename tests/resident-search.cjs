// Minimal DOM/network harness for the asynchronous search behavior.
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
class Option {
    constructor(text, value) { this.textContent = text; this.value = value; this.dataset = {}; }
}
function select(options) {
    return {options, selectedOptions: [options[0]], value: '', listeners: {},
        add(option) { this.options.push(option); },
        replaceChildren(option) { this.options = [option]; this.selectedOptions = [option]; this.value = ''; },
        addEventListener(event, callback) { this.listeners[event] = callback; }};
}
(async () => {
    const resident = select([new Option('', '')]);
    const bed = select([new Option('', ''), Object.assign(new Option('Bed', '1'), {dataset: {facility: '7'}})]);
    const search = {value: '', addEventListener(event, callback) { this.input = callback; }};
    const status = {};
    const elements = {'occupancy-resident':resident,'occupancy-bed':bed,'resident-search':search,'resident-search-status':status};
    const timers = new Map(), requests = [];
    let sequence = 0;
    const context = {Option, AbortController, encodeURIComponent, document:{getElementById:id=>elements[id]},
        setTimeout(callback) { timers.set(++sequence, callback); return sequence; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) { return new Promise(resolve => requests.push({url, options, resolve})); }};
    vm.runInNewContext(fs.readFileSync('public/assets/resident-search.js','utf8'), context);
    function input(value) { search.value = value; search.input(); }
    function flushTimer() { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(callback=>callback()); }
    async function reply(index, rows, extra={}) {
        requests[index].resolve({ok:true,redirected:false,json:async()=>rows,...extra});
        for(let i=0;i<5;i++) await Promise.resolve();
    }
    input('A'); assert.equal(timers.size,0);
    input('Al'); input('Alpha'); assert.equal(timers.size,1); flushTimer();
    assert.equal(requests.length,1);
    input('Beta'); assert.equal(requests[0].options.signal.aborted,true); flushTimer();
    await reply(1,[{id:2,facility_id:7,facility_name:'Test',display_name:'<img src=x>',reference_code:'RES-2'}]);
    assert.equal(resident.options.length,2);
    assert.match(resident.options[1].textContent, /<img src=x>/);
    await reply(0,[{id:1,facility_id:8,facility_name:'Old',display_name:'Alpha',reference_code:'RES-1'}]);
    assert.equal(resident.options[1].value,'2','stale response must not replace current results');
    resident.selectedOptions=[resident.options[1]]; resident.listeners.change();
    assert.equal(bed.options[1].disabled,false);
    input('Gamma'); assert.equal(resident.options.length,1); assert.equal(bed.options[1].disabled,true); flushTimer();
    await reply(2,[],{ok:false}); assert.match(status.textContent,/Search failed/);
    input('No match'); flushTimer(); await reply(3,[]); assert.match(status.textContent,/No available/);
    input('Expired'); flushTimer(); await reply(4,[],{redirected:true}); assert.match(status.textContent,/sign in/);
    input('x'.repeat(101)); assert.equal(timers.size,0);
    console.log('PASS: debounce, abort, stale responses, safe option text, bed filtering, empty/error/session states.');
})().catch(error=>{console.error(error);process.exitCode=1;});
