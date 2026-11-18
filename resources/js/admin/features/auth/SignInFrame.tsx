import type { ComponentType, ReactNode } from 'react';
import {
    BalloonIcon,
    CarIcon,
    GameControllerIcon,
    GiftIcon,
    HeartIcon,
    RobotIcon,
    RocketLaunchIcon,
    ShoppingBagOpenIcon,
    SoccerBallIcon,
    StarIcon,
    TrainIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';

type Tone = 'pink' | 'butter' | 'mint' | 'sky' | 'lavender' | 'peach';

/** Each toy on its pastel tile in the tile's own ink — the colour pairs the Figma badges use. */
const tones: Record<Tone, string> = {
    pink: 'bg-tile-pink text-fg-ink-pink',
    butter: 'bg-tile-butter text-fg-ink-butter',
    mint: 'bg-tile-mint text-fg-ink-mint',
    sky: 'bg-tile-sky text-fg-ink-sky',
    lavender: 'bg-tile-lavender text-fg-brand',
    peach: 'bg-tile-peach text-fg-ink-coral',
};

/** `deg` is where the badge sits on its ring, clockwise from three o'clock (Figma 595:50745). */
type Toy = { icon: ComponentType<IconProps>; deg: number; tone: Tone };

const innerToys: Toy[] = [
    { icon: TrainIcon, deg: 25, tone: 'sky' },
    { icon: CarIcon, deg: 155, tone: 'butter' },
    { icon: RocketLaunchIcon, deg: 205, tone: 'pink' },
    { icon: RobotIcon, deg: 335, tone: 'mint' },
];
const outerToys: Toy[] = [
    { icon: GiftIcon, deg: 0, tone: 'pink' },
    { icon: HeartIcon, deg: 38, tone: 'peach' },
    { icon: SoccerBallIcon, deg: 142, tone: 'sky' },
    { icon: StarIcon, deg: 180, tone: 'lavender' },
    { icon: BalloonIcon, deg: 218, tone: 'peach' },
    { icon: GameControllerIcon, deg: 322, tone: 'butter' },
];

/**
 * The sign-in screen around a card — Figma "B · 00 Sign in · Premium · C Toy orbit"
 * (desktop 595:50745, 768 596:50774, 390 596:50855): logo and language switch on top, the card
 * centred inside slowly turning orbit rings with pastel toy badges, a one-line tagline below.
 * The orbit is decoration (aria-hidden), hidden on phones, and only moves for `motion-safe`.
 */
export function SignInFrame({ children }: { children: ReactNode }) {
    const { t } = useI18n();

    return (
        <main className="relative isolate flex min-h-dvh flex-col overflow-hidden bg-canvas">
            <Orbit />

            <header className="flex items-center justify-between gap-4 px-4 pt-5 md:px-10 md:pt-8">
                <div className="flex items-center gap-3">
                    <span aria-hidden className="grid size-10 place-items-center rounded-sm bg-brand text-fg-on-brand">
                        <ShoppingBagOpenIcon size={22} weight="fill" />
                    </span>
                    <span className="flex flex-col">
                        <span lang="en" className="t-admin-card-title text-fg-primary">
                            {t('app.wordmark')}
                        </span>
                        <span className="t-admin-caption text-fg-tertiary">{t('auth.brandCaption')}</span>
                    </span>
                </div>
                <LanguageSwitch />
            </header>

            <div className="flex flex-1 items-center justify-center px-4 py-6 md:py-10">{children}</div>

            <footer className="px-4 pb-8 text-center">
                <p className="t-admin-caption text-fg-secondary">
                    <span className="t-admin-caption-strong text-fg-primary">{t('auth.tagline.title')}</span>
                    <span aria-hidden className="max-sm:hidden"> — </span>
                    <span className="max-sm:block">{t('auth.tagline.body')}</span>
                </p>
            </footer>
        </main>
    );
}

/** Rings on the canvas centre, scaled down on tablets; the toys ride the two middle rings. */
function Orbit() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 -z-10 hidden md:block">
            <div className="absolute start-1/2 top-1/2 scale-75 lg:scale-90 xl:scale-100">
                <div className="absolute size-165 -translate-1/2 rounded-full border border-line-default bg-brand-subtle/70 motion-safe:animate-orbit-breathe" />
                <Ring size="size-215" spin="motion-safe:animate-orbit-inner" counter="motion-safe:animate-orbit-inner-counter" toys={innerToys} badge="size-16" icon={28} />
                <Ring size="size-280" spin="motion-safe:animate-orbit-outer" counter="motion-safe:animate-orbit-outer-counter" toys={outerToys} badge="size-13" icon={24} />
                <div className="absolute size-350 -translate-1/2 rounded-full border border-dashed border-line-default" />
            </div>
        </div>
    );
}

function Ring({ size, spin, counter, toys, badge, icon }: { size: string; spin: string; counter: string; toys: Toy[]; badge: string; icon: number }) {
    return (
        <div className={`absolute ${size} -translate-1/2 rounded-full border border-line-default`}>
            <div className={`absolute inset-0 ${spin}`}>
                {toys.map(({ icon: Icon, deg, tone }, index) => (
                    // Turn the arm to the badge's angle, then undo it on the badge so the toy stands upright.
                    <div key={deg} className="absolute inset-0" style={{ transform: `rotate(${deg + 90}deg)` }}>
                        <div className="absolute start-1/2 top-0 -translate-1/2" style={{ transform: `rotate(${-(deg + 90)}deg)` }}>
                            <div className={counter}>
                                <span
                                    className={`grid ${badge} place-items-center rounded-full border-3 border-surface shadow-e2 motion-safe:animate-orbit-bob ${tones[tone]}`}
                                    style={{ animationDelay: `${index * -0.8}s` }}
                                >
                                    <Icon size={icon} weight="fill" />
                                </span>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
