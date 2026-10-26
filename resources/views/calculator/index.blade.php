@extends('layouts.app')

@section('page_title', 'Calculator — ' . config('app.name'))
@section('page_label', 'CALCULATOR')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> OPERATIONS TOOLKIT <span class="eyebrow-index">03 / 04</span></div><h1>Precision <span>calculator</span></h1><p class="page-description">A local calculation workspace. History is stored only in this browser.</p></div>
        <button type="button" class="portable-calc-launch" data-portable-calc-open title="Open portable calculator (Ctrl+Q)">
            <span aria-hidden="true">＋</span> QUICK CALCULATOR <kbd>CTRL Q</kbd>
        </button>
    </section>

    <section class="calculator-workspace" aria-label="Calculator and history">
        <aside class="calculation-history-panel glass-panel" aria-labelledby="history-title">
            <div class="calc-panel-heading"><div><div class="section-kicker">LOCAL RECORD</div><h2 id="history-title">Calculation history</h2></div><span class="history-count" data-history-count>00 / 100</span></div>
            <div class="history-list" data-history-list aria-live="polite"><div class="history-empty">Your calculations will appear here.</div></div>
            <button class="history-expand" type="button" data-history-expand hidden>VIEW CALCULATION HISTORY <span aria-hidden="true">↓</span></button>
            <button class="history-clear" type="button" data-history-clear hidden>CLEAR LOCAL HISTORY</button>
            <p class="history-privacy">Stored in localStorage on this device. No server history is created.</p>
        </aside>

        <section class="calculator-panel glass-panel" aria-labelledby="calculator-title" data-calculator>
            <div class="calc-panel-heading">
                <div><div class="section-kicker">VCS COMPUTE</div><h2 id="calculator-title">Calculator</h2></div>
                <label class="mode-switch" aria-label="Calculator mode">
                    <span class="mode-label is-active" data-mode-label="basic">BASIC</span>
                    <input type="checkbox" data-calc-mode aria-label="Enable scientific calculator">
                    <span class="mode-switch-track" aria-hidden="true"><span></span></span>
                    <span class="mode-label" data-mode-label="scientific">SCIENTIFIC</span>
                </label>
            </div>
            <div class="calc-display" aria-live="polite">
                <div class="calc-expression" data-calc-expression>0</div>
                <div class="calc-result" data-calc-result>0</div>
                <div class="calc-status" data-calc-status>READY <span>·</span> DEG</div>
            </div>
            <div class="calc-keypad" data-calc-keypad>
                <button class="calc-key calc-scientific-key" data-calc-action="sin(" hidden>sin</button>
                <button class="calc-key calc-scientific-key" data-calc-action="cos(" hidden>cos</button>
                <button class="calc-key calc-scientific-key" data-calc-action="tan(" hidden>tan</button>
                <button class="calc-key calc-scientific-key" data-calc-action="sqrt(" hidden>√</button>
                <button class="calc-key calc-control-key" data-calc-action="clear">AC</button>
                <button class="calc-key calc-control-key" data-calc-action="backspace" aria-label="Delete last character">⌫</button>
                <button class="calc-key calc-operation-key" data-calc-action="(">(</button>
                <button class="calc-key calc-operation-key" data-calc-action=")">)</button>
                <button class="calc-key calc-operation-key" data-calc-action="%">%</button>
                <button class="calc-key calc-scientific-key" data-calc-action="log(" hidden>log</button>
                <button class="calc-key calc-scientific-key" data-calc-action="ln(" hidden>ln</button>
                <button class="calc-key calc-scientific-key" data-calc-action="pi" hidden>π</button>
                <button class="calc-key calc-scientific-key" data-calc-action="e" hidden>e</button>
                <button class="calc-key" data-calc-action="7">7</button>
                <button class="calc-key" data-calc-action="8">8</button>
                <button class="calc-key" data-calc-action="9">9</button>
                <button class="calc-key calc-operation-key" data-calc-action="/">÷</button>
                <button class="calc-key calc-scientific-key" data-calc-action="asin(" hidden>sin⁻¹</button>
                <button class="calc-key calc-scientific-key" data-calc-action="acos(" hidden>cos⁻¹</button>
                <button class="calc-key calc-scientific-key" data-calc-action="atan(" hidden>tan⁻¹</button>
                <button class="calc-key calc-scientific-key" data-calc-action="^2" hidden>x²</button>
                <button class="calc-key" data-calc-action="4">4</button>
                <button class="calc-key" data-calc-action="5">5</button>
                <button class="calc-key" data-calc-action="6">6</button>
                <button class="calc-key calc-operation-key" data-calc-action="*">×</button>
                <button class="calc-key calc-scientific-key" data-calc-action="abs(" hidden>abs</button>
                <button class="calc-key calc-scientific-key" data-calc-action="exp(" hidden>exp</button>
                <button class="calc-key calc-scientific-key" data-calc-action="!">n!</button>
                <button class="calc-key calc-scientific-key" data-calc-action="^" hidden>xʸ</button>
                <button class="calc-key" data-calc-action="1">1</button>
                <button class="calc-key" data-calc-action="2">2</button>
                <button class="calc-key" data-calc-action="3">3</button>
                <button class="calc-key calc-operation-key" data-calc-action="-">−</button>
                <button class="calc-key calc-scientific-key" data-calc-action="floor(" hidden>floor</button>
                <button class="calc-key calc-scientific-key" data-calc-action="ceil(" hidden>ceil</button>
                <button class="calc-key calc-scientific-key" data-calc-action="round(" hidden>round</button>
                <button class="calc-key calc-scientific-key" data-calc-action="mod" hidden>mod</button>
                <button class="calc-key" data-calc-action="0">0</button>
                <button class="calc-key" data-calc-action=".">.</button>
                <button class="calc-key calc-control-key" data-calc-action="sign">±</button>
                <button class="calc-key calc-operation-key" data-calc-action="+">+</button>
                <button class="calc-key calc-scientific-key" data-calc-action="(" hidden>(</button>
                <button class="calc-key calc-scientific-key" data-calc-action=")" hidden>)</button>
                <button class="calc-key calc-scientific-key" data-calc-action="factorial" hidden>!</button>
                <button class="calc-key calc-scientific-key" data-calc-action="answer" hidden>ANS</button>
            </div>
            <div class="calc-keypad-footer"><span>KEYBOARD READY</span><button type="button" class="calc-equals" data-calc-action="equals">=</button></div>
            <p class="calculator-error" data-calc-error role="alert" hidden></p>
        </section>
    </section>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>LOCAL CALCULATIONS <i>·</i> NO SERVER STORAGE</span></footer>
@endsection