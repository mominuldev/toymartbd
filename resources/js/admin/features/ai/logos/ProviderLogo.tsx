import claudeLogo from './claude.svg';
import geminiLogo from './gemini.svg';
import openaiLogo from './openai.svg';

type Provider = 'anthropic' | 'openai' | 'gemini';

/**
 * Each AI provider's own mark on its Connect card (owner request 2026-11-17). Claude and Gemini
 * are full-colour files; OpenAI's mark is one colour, so it is painted with the current text
 * colour through a CSS mask and stays visible in dark mode. Source and licence: ./LICENSE.md.
 */
export function ProviderLogo({ provider, className = 'size-6' }: { provider: Provider; className?: string }) {
    if (provider === 'openai') {
        return <span aria-hidden className={`inline-block shrink-0 bg-current ${className}`} style={{ maskImage: `url(${openaiLogo})`, maskSize: 'contain', maskRepeat: 'no-repeat', maskPosition: 'center' }} />;
    }
    return <img src={provider === 'anthropic' ? claudeLogo : geminiLogo} alt="" aria-hidden className={`shrink-0 ${className}`} />;
}
