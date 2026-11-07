import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluateTriggerExpression } from '../../resources/js/shell-math.mjs';

test('trigger evaluates arithmetic with standard precedence and parentheses', () => {
    assert.equal(evaluateTriggerExpression('3+3'), '6');
    assert.equal(evaluateTriggerExpression('2 + 3 * 4'), '14');
    assert.equal(evaluateTriggerExpression('(2 + 3) * 4'), '20');
    assert.equal(evaluateTriggerExpression('2^3'), '8');
    assert.equal(evaluateTriggerExpression('1.25 + .75'), '2');
});

test('trigger rejects malformed arithmetic and reports division by zero', () => {
    assert.equal(evaluateTriggerExpression('connected'), null);
    assert.equal(evaluateTriggerExpression('3+window.alert(1)'), null);
    assert.throws(() => evaluateTriggerExpression('3/0'), /Cannot divide by zero/);
    assert.throws(() => evaluateTriggerExpression('(3+2'), /Missing closing parenthesis/);
});
