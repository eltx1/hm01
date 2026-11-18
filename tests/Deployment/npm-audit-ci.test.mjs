import test from 'node:test';
import assert from 'node:assert/strict';
import {evaluateFullAudit} from '../../scripts/audit-npm-ci.mjs';

const ADVISORY = 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf';
const paths = [
    'node_modules/picomatch',
    'node_modules/vite-plugin-full-reload/node_modules/picomatch',
    'node_modules/fdir',
    'node_modules/tinyglobby',
    'node_modules/vite',
    'node_modules/laravel-vite-plugin',
    'node_modules/vite-plugin-full-reload',
];
function fixture() {
    const packages = Object.fromEntries(paths.map(path => [path, {dev: true}]));
    packages[paths[0]].version = '4.0.5';
    packages[paths[1]].version = '2.3.2';
    return {
        lock: {packages},
        report: {
            metadata: {vulnerabilities: {total: 6}},
            vulnerabilities: {
                picomatch: {severity:'high', via:[{name:'picomatch',url:ADVISORY,severity:'high'}],nodes:paths.slice(0,2)},
                fdir: {severity:'high', via:['picomatch'],nodes:[paths[2]]},
                tinyglobby: {severity:'high', via:['fdir','picomatch'],nodes:[paths[3]]},
                vite: {severity:'high', via:['fdir','tinyglobby','picomatch'],nodes:[paths[4]]},
                'vite-plugin-full-reload': {severity:'high',via:['picomatch'],nodes:[paths[6]]},
                'laravel-vite-plugin': {severity:'high',via:['vite','vite-plugin-full-reload'],nodes:[paths[5]]},
            },
        },
    };
}
const now = new Date('2026-11-18T00:00:00Z');
test('accept only the exact reviewed temporary dev-only advisory chain', () => {
    const {report,lock}=fixture();
    assert.match(evaluateFullAudit(report,lock,now), /dev-only/);
});
test('clean audit passes even after exception expiry', () => {
    const {report,lock}=fixture(); report.vulnerabilities={}; report.metadata.vulnerabilities.total=0;
    assert.equal(evaluateFullAudit(report,lock,new Date('2027-01-01')), 'clean');
});
test('exception expires and fails closed', () => {
    const {report,lock}=fixture();
    assert.throws(()=>evaluateFullAudit(report,lock,new Date('2026-12-01')), /expired/);
});
test('unlisted advisories and dependency edges are rejected', () => {
    const {report,lock}=fixture();
    report.vulnerabilities.picomatch.via.push({name:'picomatch',url:'https://github.com/advisories/OTHER',severity:'high'});
    assert.throws(()=>evaluateFullAudit(report,lock,now), /unrecognized/);
    const another=fixture(); another.report.vulnerabilities.vite.via.push('unlisted');
    assert.throws(()=>evaluateFullAudit(another.report,another.lock,now), /unexpected/);
});
test('production dependency or changed pin fails closed', () => {
    const {report,lock}=fixture(); lock.packages[paths[1]].dev=false;
    assert.throws(()=>evaluateFullAudit(report,lock,now), /dev dependencies/);
    const another=fixture(); another.lock.packages[paths[0]].version='4.0.7';
    assert.throws(()=>evaluateFullAudit(another.report,another.lock,now), /location\/version changed/);
});
test('new affected package and altered audit counts fail closed', () => {
    const {report,lock}=fixture();
    report.vulnerabilities['new-package']={via:['picomatch'],nodes:['node_modules/new-package']};
    assert.throws(()=>evaluateFullAudit(report,lock,now), /unexpected/);
    const another=fixture(); another.report.metadata.vulnerabilities.total=7;
    assert.throws(()=>evaluateFullAudit(another.report,another.lock,now), /counts/);
});
test('malformed or incomplete audit evidence is rejected', () => {
    const {report,lock}=fixture();
    delete report.vulnerabilities.picomatch.nodes;
    assert.throws(()=>evaluateFullAudit(report,lock,now), /missing/);
    assert.throws(()=>evaluateFullAudit({},lock,now), /metadata/);
});
