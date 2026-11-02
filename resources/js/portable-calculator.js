import { evaluateTriggerExpression } from './shell-math.mjs';

const calculatorTemplate = document.querySelector('#portable-calculator-template');
const calculatorStorageKey = 'vcs.portable-calculators.v2';
const legacyCalculatorStorageKey = 'vcs.portable-calculator.v1';
const calculators = new Map();
let activeCalculatorId = null;
let nextCalculatorId = 1;
let nextCalculatorZIndex = 150;

const notify = (message, type = 'warning') => {
    window.vaultAlert({ type, message });
};

const persistCalculators = () => {
    try {
        const savedCalculators = [...calculators.values()].map(({ element, expression, completed, id }) => {
            const rect = element.getBoundingClientRect();

            return {
                id,
                expression,
                completed,
                left: rect.left,
                top: rect.top,
            };
        });

        if (savedCalculators.length === 0) {
            sessionStorage.removeItem(calculatorStorageKey);
            return;
        }

        sessionStorage.setItem(calculatorStorageKey, JSON.stringify({ calculators: savedCalculators }));
    } catch (error) {
        console.error('Unable to preserve portable calculator state.', error);
        notify('Unable to preserve portable calculator state in this browser session.');
    }
};

const renderCalculator = (calculator) => {
    const expressionDisplay = calculator.element.querySelector('[data-portable-calc-expression]');
    const resultDisplay = calculator.element.querySelector('[data-portable-calc-result]');
    expressionDisplay.textContent = calculator.expression || '0';

    if (!calculator.expression) {
        resultDisplay.textContent = '0';
        return;
    }

    try {
        const result = evaluateTriggerExpression(calculator.expression);
        resultDisplay.textContent = result
            ?? (/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(calculator.expression)
                ? formatCalculatorValue(Number(calculator.expression))
                : '—');
    } catch {
        resultDisplay.textContent = 'Error';
    }
};

const focusCalculator = (calculator) => {
    if (activeCalculatorId !== null) {
        calculators.get(activeCalculatorId)?.element.classList.remove('is-focused');
    }

    activeCalculatorId = calculator.id;
    calculator.element.classList.add('is-focused');
    calculator.element.style.zIndex = String(++nextCalculatorZIndex);
};

const clearCalculatorFocus = () => {
    if (activeCalculatorId === null) return;
    calculators.get(activeCalculatorId)?.element.classList.remove('is-focused');
    activeCalculatorId = null;
};

const removeCalculator = (calculator) => {
    calculator.element.remove();
    calculators.delete(calculator.id);
    if (activeCalculatorId === calculator.id) clearCalculatorFocus();
    persistCalculators();
};

const closeCalculator = (id) => {
    const calculator = calculators.get(id);
    if (calculator) removeCalculator(calculator);
};

const getCalculatorValue = (calculator) => {
    const value = Number(calculator.element.querySelector('[data-portable-calc-result]').textContent);
    if (!Number.isFinite(value)) {
        throw new Error('Both calculators must display valid numeric values before they can be summed.');
    }

    return value;
};

const formatCalculatorValue = (value) => {
    if (!Number.isFinite(value)) throw new Error('The combined value is outside the supported numeric range.');
    if (Object.is(value, -0)) return '0';

    return Number(value.toPrecision(12)).toString();
};

const createCalculatorId = () => {
    let id;
    do {
        id = `portable-calculator-${nextCalculatorId++}`;
    } while (calculators.has(id));

    return id;
};

const mergeCalculators = async (source, target) => {
    let sourceValue;
    let targetValue;

    try {
        sourceValue = getCalculatorValue(source);
        targetValue = getCalculatorValue(target);
    } catch (error) {
        notify(error instanceof Error ? error.message : 'These calculator values cannot be summed.', 'error');
        return;
    }

    let sum;
    try {
        sum = formatCalculatorValue(sourceValue + targetValue);
    } catch (error) {
        notify(error instanceof Error ? error.message : 'The calculator values could not be summed.', 'error');
        return;
    }

    let confirmed;
    try {
        confirmed = await window.vaultConfirm({
            title: 'Sum calculator values',
            message: `Add ${formatCalculatorValue(sourceValue)} and ${formatCalculatorValue(targetValue)}? The dropped calculator will close, and the receiving calculator will show ${sum}.`,
            confirmLabel: 'SUM VALUES',
        });
    } catch (error) {
        console.error('Unable to request confirmation for calculator summation.', error);
        notify('Could not confirm the calculator sum. Both calculators remain unchanged.', 'error');
        return;
    }

    if (!confirmed || !calculators.has(source.id) || !calculators.has(target.id)) return;

    target.expression = `${formatCalculatorValue(sourceValue)}+${formatCalculatorValue(targetValue)}`;
    target.completed = true;
    renderCalculator(target);
    removeCalculator(source);
    focusCalculator(target);
    persistCalculators();
};

const findMergeTarget = (source) => {
    const sourceRect = source.element.getBoundingClientRect();

    for (const candidate of calculators.values()) {
        if (candidate.id === source.id) continue;

        const targetRect = candidate.element.getBoundingClientRect();
        const overlapWidth = Math.max(0, Math.min(sourceRect.right, targetRect.right) - Math.max(sourceRect.left, targetRect.left));
        const overlapHeight = Math.max(0, Math.min(sourceRect.bottom, targetRect.bottom) - Math.max(sourceRect.top, targetRect.top));
        const widthCoverage = overlapWidth / Math.min(sourceRect.width, targetRect.width);
        const heightCoverage = overlapHeight / Math.min(sourceRect.height, targetRect.height);

        if ((widthCoverage >= 0.75 && heightCoverage >= 0.25)
            || (heightCoverage >= 0.75 && widthCoverage >= 0.25)) {
            return candidate;
        }
    }

    return null;
};

const createCalculator = (savedState = null) => {
    if (!(calculatorTemplate instanceof HTMLTemplateElement)) {
        notify('The portable calculator is unavailable. Reload the page and try again.', 'error');
        return null;
    }

    const layer = document.querySelector('[data-portable-calculator-layer]');
    if (!layer) {
        notify('The portable calculator workspace is unavailable on this page.', 'error');
        return null;
    }

    const element = calculatorTemplate.content.firstElementChild.cloneNode(true);
    const savedId = typeof savedState?.id === 'string' && savedState.id !== ''
        ? savedState.id
        : null;
    const id = savedId !== null && !calculators.has(savedId)
        ? savedId
        : createCalculatorId();
    const calculator = {
        id,
        element,
        expression: typeof savedState?.expression === 'string'
            && /^[\d.eE+\-*/%()]*$/.test(savedState.expression)
            ? savedState.expression.slice(0, 120)
            : '',
        completed: savedState?.completed === true,
        mergeTarget: null,
    };

    element.dataset.portableCalculatorId = id;
    element.style.zIndex = String(++nextCalculatorZIndex);
    calculators.set(id, calculator);
    layer.append(element);

    const offset = (calculators.size - 1) % 7;
    const initialRect = element.getBoundingClientRect();
    const left = Number.isFinite(savedState?.left)
        ? savedState.left
        : window.innerWidth - initialRect.width - 24 - offset * 52;
    const top = Number.isFinite(savedState?.top)
        ? savedState.top
        : 105 + offset * 32;
    element.style.left = `${Math.min(Math.max(8, left), Math.max(8, window.innerWidth - initialRect.width - 8))}px`;
    element.style.top = `${Math.min(Math.max(8, top), Math.max(8, window.innerHeight - initialRect.height - 8))}px`;
    element.style.right = 'auto';
    element.style.bottom = 'auto';

    element.addEventListener('pointerdown', () => focusCalculator(calculator), { capture: true });
    element.querySelector('[data-portable-calc-close]').addEventListener('click', () => closeCalculator(id));
    element.querySelector('[data-portable-calc-keys]').addEventListener('click', (event) => {
        const button = event.target.closest('[data-portable-calc-key]');
        if (!button) return;

        const action = button.dataset.portableCalcKey;
        const value = button.dataset.value ?? '';

        if (action === 'clear') {
            calculator.expression = '';
            calculator.completed = false;
        } else if (action === 'backspace') {
            calculator.expression = calculator.expression.slice(0, -1);
            calculator.completed = false;
        } else if (action === 'digit') {
            if (calculator.completed) {
                calculator.expression = '';
                calculator.completed = false;
            }
            const currentNumber = calculator.expression.split(/[+\-*/%()]/).at(-1) ?? '';
            if (value === '.' && currentNumber.includes('.')) return;
            calculator.expression += value;
        } else if (action === 'operator') {
            calculator.completed = false;
            if (!calculator.expression && value !== '-') return;
            if (/[+\-*/%]$/.test(calculator.expression)) {
                calculator.expression = calculator.expression.slice(0, -1);
            }
            calculator.expression += value;
        } else if (action === 'equals') {
            try {
                const result = evaluateTriggerExpression(calculator.expression);
                if (result === null) throw new Error('Enter a valid calculation.');
                calculator.expression = result;
                calculator.completed = true;
            } catch (error) {
                notify(error instanceof Error ? error.message : 'Unable to calculate this expression.', 'error');
                return;
            }
        }

        renderCalculator(calculator);
        persistCalculators();
    });

    const dragHandle = element.querySelector('[data-portable-calc-drag]');
    dragHandle.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button')) return;
        event.preventDefault();

        const rect = element.getBoundingClientRect();
        const startX = event.clientX;
        const startY = event.clientY;
        const leftStart = rect.left;
        const topStart = rect.top;
        dragHandle.setPointerCapture(event.pointerId);

        const move = (moveEvent) => {
            const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
            const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
            element.style.left = `${Math.min(Math.max(8, leftStart + moveEvent.clientX - startX), maxLeft)}px`;
            element.style.top = `${Math.min(Math.max(8, topStart + moveEvent.clientY - startY), maxTop)}px`;
            element.style.right = 'auto';
            element.style.bottom = 'auto';

            const nextTarget = findMergeTarget(calculator);
            if (calculator.mergeTarget !== nextTarget) {
                calculator.mergeTarget?.element.classList.remove('is-merge-target');
                calculator.mergeTarget = nextTarget;
                calculator.mergeTarget?.element.classList.add('is-merge-target');
            }
        };
        const finish = async () => {
            dragHandle.removeEventListener('pointermove', move);
            dragHandle.removeEventListener('pointerup', finish);
            dragHandle.removeEventListener('pointercancel', finish);

            const target = calculator.mergeTarget;
            calculator.mergeTarget?.element.classList.remove('is-merge-target');
            calculator.mergeTarget = null;
            if (target) {
                await mergeCalculators(calculator, target);
            } else {
                persistCalculators();
            }
        };

        dragHandle.addEventListener('pointermove', move);
        dragHandle.addEventListener('pointerup', finish);
        dragHandle.addEventListener('pointercancel', finish);
    });

    renderCalculator(calculator);
    if (!savedState) {
        focusCalculator(calculator);
        persistCalculators();
    }

    return calculator;
};

export const openPortableCalculator = () => createCalculator();

const restoreCalculators = () => {
    try {
        const stored = sessionStorage.getItem(calculatorStorageKey);
        const legacyStored = stored === null ? sessionStorage.getItem(legacyCalculatorStorageKey) : null;
        const parsed = JSON.parse(stored ?? legacyStored ?? 'null');
        const savedCalculators = Array.isArray(parsed?.calculators)
            ? parsed.calculators
            : parsed?.open === true ? [parsed] : [];

        savedCalculators.forEach((state) => createCalculator(state));
        if (savedCalculators.length > 0) {
            const lastCalculator = [...calculators.values()].at(-1);
            if (lastCalculator) focusCalculator(lastCalculator);
            persistCalculators();
        }
        if (legacyStored !== null) sessionStorage.removeItem(legacyCalculatorStorageKey);
    } catch (error) {
        console.error('Saved portable calculator state could not be restored.', error);
        notify('Saved portable calculator state could not be restored.');
    }
};

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    if (event.target.closest('[data-portable-calc-open]')) openPortableCalculator();
});

document.addEventListener('pointerdown', (event) => {
    if (!(event.target instanceof Element) || event.target.closest('[data-portable-calculator]')) return;
    clearCalculatorFocus();
});

document.addEventListener('keydown', (event) => {
    if (event.ctrlKey && !event.shiftKey && !event.altKey && event.key.toLowerCase() === 'q') {
        event.preventDefault();
        event.stopImmediatePropagation();
        openPortableCalculator();
        return;
    }

    if (event.key === 'Escape' && activeCalculatorId !== null) {
        event.preventDefault();
        event.stopImmediatePropagation();
        closeCalculator(activeCalculatorId);
    }
}, true);

document.addEventListener('submit', (event) => {
    if (!(event.target instanceof HTMLFormElement) || !event.target.matches('.sidebar-logout')) return;

    try {
        sessionStorage.removeItem(calculatorStorageKey);
        sessionStorage.removeItem(legacyCalculatorStorageKey);
    } catch (error) {
        console.error('Portable calculator state could not be cleared before sign out.', error);
        notify('Temporary calculator state could not be cleared before sign out.', 'error');
    }
});

window.addEventListener('resize', () => {
    calculators.forEach((calculator) => {
        const rect = calculator.element.getBoundingClientRect();
        const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
        const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
        calculator.element.style.left = `${Math.min(Math.max(8, rect.left), maxLeft)}px`;
        calculator.element.style.top = `${Math.min(Math.max(8, rect.top), maxTop)}px`;
    });
    persistCalculators();
});

restoreCalculators();
