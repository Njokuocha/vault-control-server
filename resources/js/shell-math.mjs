export const evaluateTriggerExpression = (source) => {
    const compactSource = source.replace(/\s+/g, '');
    if (!/[+\-*/%^()]/.test(compactSource) || !/^[\d.eE+\-*/%^()]+$/.test(compactSource)) return null;

    const tokens = compactSource.match(/(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?|[()+\-*/%^]/gi) ?? [];
    if (tokens.join('') !== compactSource) return null;

    let position = 0;
    const peek = () => tokens[position];
    const consume = () => tokens[position++];
    const parseExpression = () => {
        let value = parseTerm();
        while (peek() === '+' || peek() === '-') {
            const operator = consume();
            const right = parseTerm();
            value = operator === '+' ? value + right : value - right;
        }
        return value;
    };
    const parseTerm = () => {
        let value = parseUnary();
        while (['*', '/', '%'].includes(peek())) {
            const operator = consume();
            const right = parseUnary();
            if ((operator === '/' || operator === '%') && right === 0) throw new Error('Cannot divide by zero.');
            if (operator === '*') value *= right;
            if (operator === '/') value /= right;
            if (operator === '%') value %= right;
        }
        return value;
    };
    const parseUnary = () => {
        if (peek() === '+') {
            consume();
            return parseUnary();
        }
        if (peek() === '-') {
            consume();
            return -parseUnary();
        }
        return parsePower();
    };
    const parsePower = () => {
        const base = parsePrimary();
        if (peek() !== '^') return base;
        consume();
        return base ** parseUnary();
    };
    const parsePrimary = () => {
        const token = consume();
        if (token === '(') {
            const value = parseExpression();
            if (consume() !== ')') throw new Error('Missing closing parenthesis.');
            return value;
        }
        if (token === undefined || !/^(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(token)) {
            throw new Error('The calculation is incomplete.');
        }
        return Number(token);
    };

    const result = parseExpression();
    if (position !== tokens.length) throw new Error('The calculation contains an unexpected value.');
    if (!Number.isFinite(result)) throw new Error('The result is outside the supported numeric range.');

    return Number(Object.is(result, -0) ? 0 : result.toPrecision(12)).toString();
};
