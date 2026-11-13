import { jsonLdScript, type JsonLdNode } from '@/lib/seo/jsonld';

/** One `<script type="application/ld+json">` per node, `<` escaped so content cannot close the tag. */
export function JsonLd({ data }: { data: JsonLdNode | JsonLdNode[] }) {
    const nodes = Array.isArray(data) ? data : [data];
    return (
        <>
            {nodes.map((node, i) => (
                <script key={i} type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(node) }} />
            ))}
        </>
    );
}
