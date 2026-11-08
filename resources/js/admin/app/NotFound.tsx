import { Link } from 'react-router';

export function NotFound() {
    return (
        <section className="space-y-3">
            <h1 className="text-xl font-bold tracking-tight">Page not found</h1>
            <p className="text-sm text-neutral-600 dark:text-neutral-400">
                That admin page does not exist.
            </p>
            <Link to="/" className="text-sm font-medium underline">
                Back to dashboard
            </Link>
        </section>
    );
}
