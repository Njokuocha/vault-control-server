<template id="portable-calculator-template">
    <section class="portable-calculator" role="dialog" aria-modal="false" aria-label="Portable calculator" data-portable-calculator>
        <header class="portable-calculator-titlebar" data-portable-calc-drag>
            <span><i></i> VCS CALC</span>
            <button type="button" data-portable-calc-close aria-label="Close portable calculator">×</button>
        </header>
        <div class="portable-calculator-screen" aria-live="polite">
            <div data-portable-calc-expression>0</div>
            <output data-portable-calc-result>0</output>
        </div>
        <div class="portable-calculator-keys" data-portable-calc-keys>
            <button type="button" data-portable-calc-key="clear" class="portable-calc-clear">AC</button>
            <button type="button" data-portable-calc-key="backspace" aria-label="Delete last digit">⌫</button>
            <button type="button" data-portable-calc-key="operator" data-value="%">%</button>
            <button type="button" data-portable-calc-key="operator" data-value="/">÷</button>
            <button type="button" data-portable-calc-key="digit" data-value="7">7</button>
            <button type="button" data-portable-calc-key="digit" data-value="8">8</button>
            <button type="button" data-portable-calc-key="digit" data-value="9">9</button>
            <button type="button" data-portable-calc-key="operator" data-value="*">×</button>
            <button type="button" data-portable-calc-key="digit" data-value="4">4</button>
            <button type="button" data-portable-calc-key="digit" data-value="5">5</button>
            <button type="button" data-portable-calc-key="digit" data-value="6">6</button>
            <button type="button" data-portable-calc-key="operator" data-value="-">−</button>
            <button type="button" data-portable-calc-key="digit" data-value="1">1</button>
            <button type="button" data-portable-calc-key="digit" data-value="2">2</button>
            <button type="button" data-portable-calc-key="digit" data-value="3">3</button>
            <button type="button" data-portable-calc-key="operator" data-value="+">+</button>
            <button type="button" data-portable-calc-key="digit" data-value="0" class="portable-calc-zero">0</button>
            <button type="button" data-portable-calc-key="digit" data-value=".">.</button>
            <button type="button" data-portable-calc-key="equals" class="portable-calc-equals">=</button>
        </div>
        <footer class="portable-calculator-footer">BASIC MODE <span>·</span> CTRL + Q</footer>
    </section>
</template>
