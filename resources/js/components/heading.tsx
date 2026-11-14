/**
 * A titled section. It is an h2 unless `level={1}` makes it the page's title,
 * which only a screen without a list's PageHeader does: one h1 per page.
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    level = 2,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    level?: 1 | 2;
}) {
    const Tag = level === 1 ? 'h1' : 'h2';

    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <Tag
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : level === 1
                          ? 'text-[22px] leading-tight font-semibold tracking-[-0.015em]'
                          : 'text-xl font-semibold tracking-tight'
                }
            >
                {title}
            </Tag>
            {description && (
                <p className="text-muted-foreground text-sm">{description}</p>
            )}
        </header>
    );
}
