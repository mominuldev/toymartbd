'use client';

import { useState } from 'react';

import { Icon } from '@/components/icons/Icon';

interface Props {
    /** The `<form>` the quantity belongs to (the input sits inside it). */
    max: number;
    disabled: boolean;
    labels: { quantity: string; increase: string; decrease: string; maxReached: string };
}

// QuantityStepper — Figma 50:567. Size=Medium at 390 (44 tall: cells 44/48/44, 136 wide) and
// Size=Large at 1440 (52: 52/56/52, 160). Dividers are 1px overlays so the cells keep their size.
// The value is a real number input named `quantity`, so without JavaScript it is still editable
// and submitted; the buttons add ±1 once hydrated. At the ceiling (`max` = units available, the
// API's number) plus is disabled AND says why (AtCeiling) — a plus that silently stops reads as broken.
export function QuantityStepper({ max, disabled, labels }: Props) {
    const ceiling = Math.max(1, max);
    const [value, setValue] = useState(1);
    const atCeiling = !disabled && value >= ceiling;
    const cell = 'relative flex h-full w-11 items-center justify-center text-fg-primary disabled:text-fg-disabled lg:w-13 hover:not-disabled:bg-fg-primary/8';

    return (
        <div className="flex flex-col items-end gap-2 lg:items-start">
            <div className="relative flex h-control-md w-34 items-center overflow-hidden rounded-full border border-line-default bg-surface lg:h-control-lg lg:w-40">
                <button type="button" className={cell} aria-label={labels.decrease} disabled={disabled || value <= 1} onClick={() => setValue((v) => Math.max(1, v - 1))}>
                    <Icon name="minus" className="size-icon-lg" />
                </button>
                <input
                    name="quantity"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={ceiling}
                    value={value}
                    disabled={disabled}
                    aria-label={labels.quantity}
                    onChange={(e) => {
                        const n = Math.trunc(Number(e.target.value));
                        setValue(Number.isFinite(n) ? Math.min(ceiling, Math.max(1, n)) : 1);
                    }}
                    className="t-stepper-value h-full w-12 appearance-none border-x border-line-default bg-transparent text-center tabular-nums text-fg-primary [-moz-appearance:textfield] focus-visible:shadow-none lg:w-14 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                />
                <button type="button" className={cell} aria-label={labels.increase} disabled={disabled || atCeiling} onClick={() => setValue((v) => Math.min(ceiling, v + 1))}>
                    <Icon name="plus" className="size-icon-lg" />
                </button>
            </div>
            {atCeiling && max > 0 ? (
                <p role="status" className="t-caption text-status-low-stock">
                    {labels.maxReached}
                </p>
            ) : null}
        </div>
    );
}
