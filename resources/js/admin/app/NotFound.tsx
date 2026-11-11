import { Link } from 'react-router';

export function NotFound() {
    return (
        <section className="space-y-3">
            <h1 className="t-admin-page-title">Page not found</h1>
            <p className="t-admin-body text-fg-secondary">
                That admin page does not exist.
            </p>
            <Link to="/" className="t-admin-body-strong text-fg-link underline">
                Back to dashboard
            </Link>
        </section>
    );
}
