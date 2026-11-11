import { Anek_Bangla, Baloo_Da_2, Fredoka, Hanken_Grotesk } from 'next/font/google';

// Self-hosted at build time by next/font (no request to Google at runtime). Only the weights the
// storefront type scale uses (UI-UX-DESIGN-SPEC §3.2). Each exposes a CSS variable that
// app/globals.css maps onto the token font variables, so the `.t-*` classes pick them up.

/** UI/body Latin: body 400 · caption 500 · label/button-sm 600 · overline 700. */
export const hanken = Hanken_Grotesk({
    subsets: ['latin'],
    weight: ['400', '500', '600', '700'],
    display: 'swap',
    variable: '--font-hanken',
});

/**
 * Display Latin: price-was 400 · card-title 500 · h3/h4/button/badge/chip 600 · display/h1/h2/price 700
 * (the Figma Latin display styles are Fredoka Bold; Fredoka has no 800).
 */
export const fredoka = Fredoka({
    subsets: ['latin'],
    weight: ['400', '500', '600', '700'],
    display: 'swap',
    variable: '--font-fredoka',
});

/** UI/body Bangla: body 400 · caption 500 · label/overline 600. Not preloaded: only Bangla text pulls it. */
export const anekBangla = Anek_Bangla({
    subsets: ['bengali', 'latin'],
    weight: ['400', '500', '600'],
    display: 'swap',
    variable: '--font-anek-bangla',
    preload: false,
});

/** Display Bangla: price-was 400 · card-title 500 · h3/h4/button 600 · h1/h2/price 700 · display/price-lg 800. */
export const balooDa2 = Baloo_Da_2({
    subsets: ['bengali', 'latin'],
    weight: ['400', '500', '600', '700', '800'],
    display: 'swap',
    variable: '--font-baloo-da-2',
    preload: false,
});

export const fontVariables = [hanken.variable, fredoka.variable, anekBangla.variable, balooDa2.variable].join(' ');
