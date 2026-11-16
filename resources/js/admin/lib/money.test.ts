import { describe, expect, it } from 'vitest';
import { formatCount, formatDigits, formatMoney, groupLakh, isNegativeAmount, isZeroAmount, takaText } from './money';

describe('groupLakh', () => {
    it.each([
        ['0', '0'],
        ['999', '999'],
        ['1000', '1,000'],
        ['99999', '99,999'],
        ['100000', '1,00,000'],
        ['1234567', '12,34,567'],
        ['123456789', '12,34,56,789'],
        ['0001250', '1,250'],
    ])('%s → %s', (input, expected) => {
        expect(groupLakh(input)).toBe(expected);
    });
});

describe('formatMoney', () => {
    it('groups by lakh and trims by default', () => {
        expect(formatMoney('0')).toBe('0');
        expect(formatMoney('0.00')).toBe('0');
        expect(formatMoney('999.00')).toBe('999');
        expect(formatMoney('1000.00')).toBe('1,000');
        expect(formatMoney('99999.00')).toBe('99,999');
        expect(formatMoney('100000.00')).toBe('1,00,000');
        expect(formatMoney('1234567.89')).toBe('12,34,567.89');
        expect(formatMoney('1050.50')).toBe('1,050.5');
    });

    it('shows two places in fixed mode, padding but never dropping what was sent', () => {
        expect(formatMoney('0', 'fixed')).toBe('0.00');
        expect(formatMoney('124300', 'fixed')).toBe('1,24,300.00');
        expect(formatMoney('1234567.89', 'fixed')).toBe('12,34,567.89');
        expect(formatMoney('1675.5', 'fixed')).toBe('1,675.50');
        expect(formatMoney('2937.7371', 'fixed')).toBe('2,937.7371');
        expect(formatMoney('312.5000', 'fixed')).toBe('312.50');
    });

    it('drops .00 for whole taka in auto mode', () => {
        expect(formatMoney('13910.00', 'auto')).toBe('13,910');
        expect(formatMoney('602.5', 'auto')).toBe('602.50');
        expect(formatMoney('1.2800', 'auto')).toBe('1.28');
    });

    it('rounds half away from zero on the digits in round mode', () => {
        expect(formatMoney('832.1429', 'round')).toBe('832.14');
        expect(formatMoney('832.145', 'round')).toBe('832.15');
        expect(formatMoney('99999.995', 'round')).toBe('1,00,000.00');
        expect(formatMoney('-0.004', 'round')).toBe('0.00');
        expect(formatMoney('-100.5', 'round')).toBe('−100.50');
        expect(formatMoney('7', 'round')).toBe('7.00');
    });

    it('carries a true minus on negatives, never on zero', () => {
        expect(formatMoney('-1675.00', 'fixed')).toBe('−1,675.00');
        expect(formatMoney('-124300.5')).toBe('−1,24,300.5');
        expect(formatMoney('-0.00')).toBe('0');
        expect(formatMoney('-0', 'fixed')).toBe('0.00');
    });

    it('keeps every digit of very large values (no float)', () => {
        expect(formatMoney('123456789.00', 'fixed')).toBe('12,34,56,789.00');
        expect(formatMoney('999999999999.99', 'fixed')).toBe('9,99,99,99,99,999.99');
        expect(formatMoney('12345678901234567890.12', 'fixed')).toBe('1,23,45,67,89,01,23,45,67,890.12');
    });
});

describe('takaText', () => {
    it('puts the minus before the taka sign', () => {
        expect(takaText('-1675.00', 'fixed')).toBe('−৳1,675.00');
        expect(takaText('124300.00', 'fixed')).toBe('৳1,24,300.00');
        expect(takaText('124300.00')).toBe('৳1,24,300');
    });

    it('marks money in with plus only when asked and non-zero', () => {
        expect(takaText('500.00', 'fixed', { plus: true })).toBe('+৳500.00');
        expect(takaText('0.00', 'fixed', { plus: true })).toBe('৳0.00');
        expect(takaText('-500.00', 'fixed', { plus: true })).toBe('−৳500.00');
    });
});

describe('helpers', () => {
    it('reads zero and negative on the string', () => {
        expect(isZeroAmount('0.00')).toBe(true);
        expect(isZeroAmount('-0.00')).toBe(true);
        expect(isZeroAmount('0.01')).toBe(false);
        expect(isNegativeAmount('-0.00')).toBe(false);
        expect(isNegativeAmount('-0.01')).toBe(true);
    });

    it('returns digits without a sign', () => {
        expect(formatDigits('-124300.00', 'fixed')).toBe('1,24,300.00');
    });

    it('groups counts by lakh with Western digits', () => {
        expect(formatCount(0)).toBe('0');
        expect(formatCount(999)).toBe('999');
        expect(formatCount(1000)).toBe('1,000');
        expect(formatCount(99999)).toBe('99,999');
        expect(formatCount(100000)).toBe('1,00,000');
        expect(formatCount(1234567)).toBe('12,34,567');
    });
});
