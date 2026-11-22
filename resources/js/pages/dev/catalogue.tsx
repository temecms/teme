import { Head, usePage } from '@inertiajs/react';
import {
    ArchiveIcon,
    ChevronDownIcon,
    CopyIcon,
    ChevronRightIcon,
    InfoIcon,
    MoreHorizontalIcon,
    OctagonAlertIcon,
    PencilIcon,
    PlusIcon,
    SearchIcon,
    Trash2Icon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Kbd } from '@/components/ui/kbd';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TextButton } from '@/components/ui/text-button';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Calendar, Image as ImageIcon, Wrench } from 'lucide-react';
import { IconTile } from '@/components/ui/icon-tile';
import { PersonPicture } from '@/components/ui/person-picture';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { PluginMark } from '@/components/ui/plugin-mark';
import { SoonTag } from '@/components/ui/soon-tag';
import { StatusPill } from '@/components/ui/status-pill';
import { StepBar } from '@/components/ui/step-bar';
import { TermChips } from '@/components/ui/term-chips';
import { showToast } from '@/components/flash-toast';
import { EmptyState } from '@/components/list/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { Notice } from '@/components/ui/notice';
import { useEffect, useState } from 'react';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { SaveBar } from '@/components/patterns/save-bar';
import { DetailPage } from '@/components/patterns/detail-page';
import { FormPage, FormPanel } from '@/components/patterns/form-page';
import { HubCard, HubPage } from '@/components/patterns/hub-page';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import {
    ResponsiveMenu,
    ResponsiveMenuContent,
    ResponsiveMenuItem,
    ResponsiveMenuLabel,
    ResponsiveMenuSeparator,
    ResponsiveMenuTrigger,
} from '@/components/patterns/responsive-menu';
import { ResponsiveSheetContent } from '@/components/patterns/responsive-sheet';
import { ListPage } from '@/components/patterns/list-page';
import { PluginAreaPage } from '@/components/patterns/plugin-area-page';
import { SettingsPage } from '@/components/patterns/settings-page';
import { __ } from '@/lib/i18n';
import { openMediaPicker } from '@/components/media/media-picker';
import type { MediaPickerCaller } from '@/components/media/media-picker';

/** A colour token: its variable, the class that fills a ground with it, and the class of the words that sit on it, if any. */
type Swatch = { token: string; ground: string; ink?: string };

/** The colour tokens of docs/architecture/design-system.md, sections 2.1 to 2.3, in its order. */
const SWATCHES: { group: string; swatches: Swatch[] }[] = [
    {
        group: 'neutral',
        swatches: [
            { token: '--page', ground: 'bg-page', ink: 'text-ink' },
            { token: '--chrome', ground: 'bg-chrome', ink: 'text-ink-2' },
            { token: '--surface', ground: 'bg-surface', ink: 'text-ink' },
            { token: '--surface-2', ground: 'bg-surface-2', ink: 'text-ink-3' },
            { token: '--surface-3', ground: 'bg-surface-3', ink: 'text-ink-2' },
            { token: '--surface-4', ground: 'bg-surface-4', ink: 'text-ink' },
            { token: '--line', ground: 'bg-line' },
            { token: '--edge', ground: 'bg-edge' },
            { token: '--ink', ground: 'bg-ink', ink: 'text-page' },
            { token: '--ink-2', ground: 'bg-ink-2' },
            { token: '--ink-3', ground: 'bg-ink-3' },
            { token: '--neutral-dot', ground: 'bg-neutral-dot' },
            { token: '--scrim', ground: 'bg-scrim' },
        ],
    },
    {
        group: 'content',
        swatches: [
            { token: '--img-outline', ground: 'bg-img-outline' },
            {
                token: '--photo-scrim',
                ground: 'bg-photo-scrim',
                ink: 'text-on-photo',
            },
            { token: '--paper', ground: 'bg-paper' },
        ],
    },
    {
        group: 'accent',
        swatches: [
            { token: '--accent', ground: 'bg-accent', ink: 'text-on-accent' },
            {
                token: '--accent-hover',
                ground: 'bg-accent-hover',
                ink: 'text-on-accent',
            },
            { token: '--accent-ink', ground: 'bg-accent-ink' },
            {
                token: '--accent-soft',
                ground: 'bg-accent-soft',
                ink: 'text-on-accent-soft',
            },
            {
                token: '--accent-soft-2',
                ground: 'bg-accent-soft-2',
                ink: 'text-on-accent-soft',
            },
            { token: '--ring', ground: 'bg-ring' },
            { token: '--chart-1', ground: 'bg-chart-1' },
            { token: '--chart-2', ground: 'bg-chart-2' },
        ],
    },
    {
        group: 'status',
        swatches: [
            { token: '--ok', ground: 'bg-ok' },
            { token: '--ok-soft', ground: 'bg-ok-soft', ink: 'text-ok-ink' },
            { token: '--info', ground: 'bg-info' },
            {
                token: '--info-soft',
                ground: 'bg-info-soft',
                ink: 'text-info-ink',
            },
            { token: '--warn', ground: 'bg-warn' },
            {
                token: '--warn-soft',
                ground: 'bg-warn-soft',
                ink: 'text-warn-ink',
            },
            { token: '--danger', ground: 'bg-danger', ink: 'text-on-danger' },
            {
                token: '--danger-hover',
                ground: 'bg-danger-hover',
                ink: 'text-on-danger',
            },
            {
                token: '--danger-soft',
                ground: 'bg-danger-soft',
                ink: 'text-danger-ink',
            },
            { token: '--ai', ground: 'bg-ai' },
            { token: '--ai-soft', ground: 'bg-ai-soft', ink: 'text-ai-ink' },
        ],
    },
];

/** The six sizes of section 2.8, each with the class that sets it. */
const SIZES = [
    { name: 'text-caption', className: 'text-caption' },
    { name: 'text-small', className: 'text-small' },
    { name: 'text-body', className: 'text-body' },
    { name: 'text-lead', className: 'text-lead' },
    { name: 'text-title', className: 'text-title font-semibold' },
    { name: 'text-display', className: 'text-display font-semibold' },
];

const RADII = [
    { name: 'rounded-xs', className: 'rounded-xs' },
    { name: 'rounded-sm', className: 'rounded-sm' },
    { name: 'rounded-md', className: 'rounded-md' },
    { name: 'rounded-lg', className: 'rounded-lg' },
    { name: 'rounded-xl', className: 'rounded-xl' },
    { name: 'rounded-full', className: 'rounded-full' },
];

/** The button variants of section 4.1, each with the name the catalogue gives it. */
const VARIANTS = [
    'primary',
    'neutral',
    'ghost',
    'danger',
    'danger-quiet',
] as const;

const SIZES_OF_BUTTONS = ['md', 'sm', 'xs'] as const;

const DIALOG_SIZES = ['sm', 'md', 'lg', 'xl'] as const;

const BADGES = [
    'default',
    'secondary',
    'success',
    'destructive',
    'outline',
] as const;

const TONES = ['accent', 'ok', 'danger', 'quiet'] as const;

const SHADOWS = [
    { name: 'shadow-raised', className: 'shadow-raised' },
    { name: 'shadow-float', className: 'shadow-float' },
    { name: 'shadow-modal', className: 'shadow-modal' },
];

/** A button with a chevron that turns as its region opens (section 4.40). */
function Disclosure({
    label,
    defaultOpen = false,
}: {
    label: string;
    defaultOpen?: boolean;
}) {
    return (
        <Collapsible defaultOpen={defaultOpen}>
            <CollapsibleTrigger asChild>
                <Button variant="ghost" className="group">
                    <ChevronRightIcon className="transition-[rotate] duration-(--dur-2) ease-(--ease) group-data-[state=open]:rotate-90 rtl:-scale-x-100" />
                    {label}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="text-ink-2 ps-10">
                {__('Fields most posts leave as they are.')}
            </CollapsibleContent>
        </Collapsible>
    );
}

/** One section of the catalogue: an anchor, its heading and a line on what it shows. */
function Section({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="grid min-w-0 scroll-mt-16 gap-4"
        >
            <div className="grid gap-1">
                <h2 id={`${id}-title`} className="text-lead font-semibold">
                    {title}
                </h2>
                <p className="text-ink-2">{description}</p>
            </div>
            {children}
        </section>
    );
}

/** Opens the media picker for each caller, and shows the IDs it hands back. */
function MediaPickerDemo() {
    const [chosen, setChosen] = useState<string[]>([]);
    const [closed, setClosed] = useState(0);
    const callers: {
        caller: MediaPickerCaller;
        test: string;
        label: string;
        multi?: boolean;
        action?: string;
    }[] = [
        { caller: 'insert', test: 'insert', label: __('Insert an image') },
        { caller: 'replace', test: 'replace', label: __('Replace the image') },
        {
            caller: 'featured',
            test: 'featured',
            label: __('Choose the featured image'),
        },
        {
            caller: 'gallery',
            test: 'gallery',
            label: __('Add images to the gallery'),
            multi: true,
        },
        {
            caller: 'choose',
            test: 'choose',
            label: __('Choose a poster'),
            action: __('Use as poster'),
        },
    ];

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-2">
                {callers.map((each) => (
                    <Button
                        key={each.test}
                        type="button"
                        variant="neutral"
                        data-test={`catalogue-picker-${each.test}`}
                        onClick={() =>
                            openMediaPicker({
                                caller: each.caller,
                                multi: each.multi ?? false,
                                action: each.action,
                                onInsert: (ids) => setChosen(ids),
                                onClose: () => setClosed((count) => count + 1),
                            })
                        }
                    >
                        {each.label}
                    </Button>
                ))}
            </div>
            <p className="text-small text-ink-2">
                {__('Chosen:')}{' '}
                <output data-test="catalogue-picker-chosen">
                    {chosen.join(',')}
                </output>
            </p>
            <p className="text-small text-ink-2">
                {__('Closed without a choice:')}{' '}
                <output data-test="catalogue-picker-closed">{closed}</output>
            </p>
        </div>
    );
}

/** The loading and failed states: a skeleton mounted on request, and a failure with Try again. */
function StatesDemo() {
    const [loading, setLoading] = useState(false);

    return (
        <div className="grid max-w-xl gap-4">
            <div>
                <Button
                    type="button"
                    variant="neutral"
                    data-test="catalogue-load"
                    onClick={() => setLoading(true)}
                >
                    {__('Load')}
                </Button>
            </div>
            {loading && (
                <LoadingState
                    data-test="catalogue-loading"
                    label={__('Loading the posts')}
                />
            )}
            <ErrorState
                data-test="catalogue-error"
                message={__('The posts couldn’t be loaded.')}
                onRetry={() => setLoading(true)}
            />
        </div>
    );
}

/** A page of the sample plugin's area that throws once asked to, as a plugin's page can. */
function FailingAreaPage({ name, fail }: { name: string; fail: boolean }) {
    if (fail) {
        throw new Error(`The sample page ${name} failed.`);
    }

    return (
        <p data-test="plugin-area-body" className="text-body text-ink-2">
            {__('This is the :page page.', { page: name })}
        </p>
    );
}

/** A plugin's own area, its current page read from the address, with a page that can be made to fail. */
function PluginAreaDemo() {
    const { url } = usePage();
    const [failing, setFailing] = useState(false);

    // Fails once: by the time Try again is pressed, the page draws again.
    useEffect(() => {
        if (failing) {
            setFailing(false);
        }
    }, [failing]);
    const area =
        new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '').get(
            'area',
        ) ?? 'overview';
    const pages = [
        { key: 'overview', label: __('Overview') },
        { key: 'link-report', label: __('Link report') },
        { key: 'orphaned-posts', label: __('Orphaned posts') },
        { key: 'broken-links', label: __('Broken links'), count: 12 },
        { key: 'settings', label: __('Settings') },
        { key: 'exports', label: __('Exports') },
        { key: 'logs', label: __('Logs') },
    ].map(({ key, ...page }) => ({
        ...page,
        href: `?area=${key}#plugin-area`,
        current: key === area,
    }));
    const current = pages.find((page) => page.current) ?? pages[0];

    return (
        <PluginAreaPage
            titleAs="div"
            plugin={{ name: __('Link checker') }}
            title={__('Link checker')}
            description={__(
                'Finds links on the site that lead nowhere, and posts nothing links to.',
            )}
            actions={
                <Button type="button" variant="neutral">
                    {__('Check now')}
                </Button>
            }
            pages={pages}
        >
            <div className="grid justify-items-start gap-3">
                <FailingAreaPage name={current.label} fail={failing} />
                <Button
                    type="button"
                    variant="neutral"
                    size="sm"
                    data-test="plugin-area-break"
                    onClick={() => setFailing(true)}
                >
                    {__('Make this page fail')}
                </Button>
            </div>
        </PluginAreaPage>
    );
}

/** A small form of two fields with the save bar, which shows once something changes. */
function SaveBarDemo() {
    const [name, setName] = useState('');
    const [line, setLine] = useState('');

    return (
        <form
            className="grid max-w-md gap-4"
            onSubmit={(event) => event.preventDefault()}
        >
            <Field label={__('Site name')} htmlFor="catalogue-save-bar-name">
                <Input
                    id="catalogue-save-bar-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </Field>
            <Field label={__('Tagline')} htmlFor="catalogue-save-bar-line">
                <Input
                    id="catalogue-save-bar-line"
                    value={line}
                    onChange={(event) => setLine(event.target.value)}
                />
            </Field>
            <SaveBar
                dirty={name !== '' || line !== ''}
                processing={false}
                onDiscard={() => {
                    setName('');
                    setLine('');
                }}
                what={__('the site')}
            />
        </form>
    );
}

/** A sensitive act behind the fresh-password prompt, saying so once it may go on. */
function FreshPasswordDemo() {
    const [open, setOpen] = useState(false);
    const [confirmed, setConfirmed] = useState(false);

    return (
        <div className="grid justify-items-start gap-3">
            <Button
                type="button"
                variant="neutral"
                data-test="catalogue-fresh-password"
                onClick={() => setOpen(true)}
            >
                {__('Download the recovery key')}
            </Button>
            <p data-test="catalogue-fresh-password-done" className="text-ink-2">
                {confirmed ? __('Confirmed') : '—'}
            </p>
            <FreshPasswordDialog
                open={open}
                onOpenChange={setOpen}
                title={__('Confirm it’s you')}
                onConfirmed={() => setConfirmed(true)}
            />
        </div>
    );
}

/** One pattern drawn small, in a frame of its own. */
function PatternFrame({
    name,
    children,
}: {
    name: string;
    children: ReactNode;
}) {
    return (
        <div
            data-test={`catalogue-pattern-${name}`}
            className="border-line min-w-0 overflow-x-clip rounded-lg border"
        >
            {children}
        </div>
    );
}

/** The Combobox in every state: one choice, several, an empty list, a search under way, disabled and in error. */
function ComboboxDemo() {
    const authors: ComboboxOption[] = [
        __('Ada Lovelace'),
        __('Alan Turing'),
        __('Barbara Liskov'),
        __('Claude Shannon'),
        __('Donald Knuth'),
        __('Edsger Dijkstra'),
        __('Frances Allen'),
        __('Grace Hopper'),
        __('Hedy Lamarr'),
        __('Ivan Sutherland'),
        __('John McCarthy'),
        __('Karen Spärck Jones'),
        __('Leslie Lamport'),
        __('Margaret Hamilton'),
        __('Mary Kenneth Keller'),
        __('Niklaus Wirth'),
        __('Radia Perlman'),
        __('Robin Milner'),
        __('Sophie Wilson'),
        __('Tim Berners-Lee'),
        __('Tony Hoare'),
        __('Vint Cerf'),
        __('Whitfield Diffie'),
        __('Yukihiro Matsumoto'),
        __('Adele Goldberg'),
        __('Brian Kernighan'),
        __('Carol Shaw'),
        __('Dennis Ritchie'),
    ].map((label, index) => ({
        value: `author-${index + 1}`,
        label,
    }));

    // Two people with one name, told apart by their second line.
    authors.push(
        {
            value: 'author-29',
            label: __('Sam Rivers'),
            description: __('Editor, Travel desk'),
        },
        {
            value: 'author-30',
            label: __('Sam Rivers'),
            description: __('Contributor, Food desk'),
        },
    );

    const tags: ComboboxOption[] = [
        __('Harbor'),
        __('Ferries'),
        __('Islands'),
        __('Lighthouses'),
        __('Tides'),
        __('Fishing'),
        __('Boatbuilding'),
        __('Coastal walks'),
        __('Seabirds'),
        __('Weather'),
        __('Markets'),
        __('Festivals'),
        __('History'),
        __('Food'),
        __('Music'),
        __('Museums'),
        __('Beaches'),
        __('Cliffs'),
        __('Sailing'),
        __('Swimming'),
    ].map((label, index) => ({ value: `tag-${index + 1}`, label }));

    const [author, setAuthor] = useState<ComboboxOption | null>(authors[2]);
    const [chosenTags, setChosenTags] = useState<ComboboxOption[]>([
        tags[1],
        tags[4],
    ]);
    const [searching, setSearching] = useState('');

    return (
        <div className="grid max-w-md gap-4">
            <Field htmlFor="catalogue-combobox-single" label={__('Author')}>
                <Combobox
                    id="catalogue-combobox-single"
                    name="author"
                    options={authors}
                    value={author}
                    onValueChange={setAuthor}
                    placeholder={__('Choose an author')}
                    searchPlaceholder={__('Search authors')}
                />
            </Field>
            <Field htmlFor="catalogue-combobox-multi" label={__('Tags')}>
                <Combobox
                    id="catalogue-combobox-multi"
                    multiple
                    name="tags[]"
                    options={tags}
                    value={chosenTags}
                    onValueChange={setChosenTags}
                    removeLabel={(option) =>
                        __('Remove :name', { name: option.label })
                    }
                    placeholder={__('Choose tags')}
                    searchPlaceholder={__('Search tags')}
                />
            </Field>
            <Field
                htmlFor="catalogue-combobox-empty-list"
                label={__('Parent page')}
            >
                <Combobox
                    id="catalogue-combobox-empty-list"
                    options={[]}
                    value={null}
                    onValueChange={() => {}}
                    placeholder={__('No parent')}
                    searchPlaceholder={__('Search pages')}
                />
            </Field>
            <Field htmlFor="catalogue-combobox-searching" label={__('Link to')}>
                <Combobox
                    id="catalogue-combobox-searching"
                    options={[]}
                    value={null}
                    onValueChange={() => {}}
                    search={{
                        value: searching,
                        onChange: setSearching,
                        loading: true,
                    }}
                    placeholder={__('Choose a post')}
                    searchPlaceholder={__('Search posts')}
                />
            </Field>
            <Field htmlFor="catalogue-combobox-disabled" label={__('Reviewer')}>
                <Combobox
                    id="catalogue-combobox-disabled"
                    disabled
                    options={authors}
                    value={authors[0]}
                    onValueChange={() => {}}
                    placeholder={__('Choose a reviewer')}
                    searchPlaceholder={__('Search people')}
                />
            </Field>
            <Field
                htmlFor="catalogue-combobox-error"
                label={__('Owner')}
                error={__('Choose who owns this page.')}
            >
                <Combobox
                    id="catalogue-combobox-error"
                    options={authors}
                    value={null}
                    onValueChange={() => {}}
                    placeholder={__('Choose an owner')}
                    searchPlaceholder={__('Search people')}
                />
            </Field>
        </div>
    );
}

/** The phone overlays: a dialog with a long body, a confirmation from the foot and a side sheet, each through its pattern. */
function PhoneOverlaysDemo() {
    const fields = [
        __('Title'),
        __('Subtitle'),
        __('Address'),
        __('Author'),
        __('Category'),
        __('Tags'),
        __('Summary'),
        __('Image description'),
        __('Search title'),
        __('Search description'),
        __('Social title'),
        __('Social description'),
    ];

    const [chosen, setChosen] = useState<string | null>(null);
    const items = [
        { name: __('Edit'), icon: PencilIcon },
        { name: __('Duplicate'), icon: CopyIcon },
        { name: __('Archive'), icon: ArchiveIcon, disabled: true },
    ];

    return (
        <div className="grid justify-items-start gap-3">
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="neutral"
                        data-test="catalogue-overlay-dialog"
                    >
                        {__('Edit the details')}
                    </Button>
                </DialogTrigger>
                <ResponsiveDialogContent
                    size="md"
                    data-test="catalogue-overlay-dialog-content"
                >
                    <DialogHeader>
                        <DialogTitle>{__('Edit the details')}</DialogTitle>
                        <DialogDescription>
                            {__('Only you see these changes.')}
                        </DialogDescription>
                    </DialogHeader>
                    {fields.map((label, index) => (
                        <Field
                            key={label}
                            htmlFor={`catalogue-overlay-field-${index}`}
                            label={label}
                        >
                            <Input id={`catalogue-overlay-field-${index}`} />
                        </Field>
                    ))}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="neutral">{__('Cancel')}</Button>
                        </DialogClose>
                        <DialogClose asChild>
                            <Button
                                variant="primary"
                                data-test="catalogue-overlay-dialog-save"
                            >
                                {__('Save')}
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </ResponsiveDialogContent>
            </Dialog>
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="neutral"
                        data-test="catalogue-overlay-confirm"
                    >
                        {__('Delete this draft?')}
                    </Button>
                </DialogTrigger>
                <ResponsiveDialogContent
                    phone="foot"
                    data-test="catalogue-overlay-confirm-content"
                >
                    <DialogHeader>
                        <DialogTitle>{__('Delete this draft?')}</DialogTitle>
                        <DialogDescription>
                            {__('It is gone for good.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                variant="neutral"
                                data-test="catalogue-overlay-confirm-cancel"
                            >
                                {__('Cancel')}
                            </Button>
                        </DialogClose>
                        <DialogClose asChild>
                            <Button variant="danger">{__('Delete')}</Button>
                        </DialogClose>
                    </DialogFooter>
                </ResponsiveDialogContent>
            </Dialog>
            <Sheet>
                <SheetTrigger asChild>
                    <Button
                        variant="neutral"
                        data-test="catalogue-overlay-sheet"
                    >
                        {__('Details')}
                    </Button>
                </SheetTrigger>
                <ResponsiveSheetContent
                    side="right"
                    data-test="catalogue-overlay-sheet-content"
                >
                    <SheetHeader>
                        <SheetTitle>{__('Details')}</SheetTitle>
                        <SheetDescription>
                            {__('A page, published.')}
                        </SheetDescription>
                    </SheetHeader>
                    <p className="text-ink-2 px-6">
                        {__('Changes apply on the next visit.')}
                    </p>
                </ResponsiveSheetContent>
            </Sheet>
            <ResponsiveMenu>
                <ResponsiveMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        data-test="catalogue-overlay-menu"
                        aria-label={__('More actions for :name', {
                            name: __('Sample post'),
                        })}
                    >
                        <MoreHorizontalIcon />
                    </Button>
                </ResponsiveMenuTrigger>
                <ResponsiveMenuContent title={__('More actions')} align="start">
                    <ResponsiveMenuLabel>
                        {__('Sample post')}
                    </ResponsiveMenuLabel>
                    {items.map((item) => (
                        <ResponsiveMenuItem
                            key={item.name}
                            disabled={item.disabled}
                            onSelect={() => setChosen(item.name)}
                        >
                            <item.icon />
                            {item.name}
                        </ResponsiveMenuItem>
                    ))}
                    <ResponsiveMenuSeparator />
                    <ResponsiveMenuItem
                        variant="destructive"
                        onSelect={() => setChosen(__('Move to trash'))}
                    >
                        <Trash2Icon />
                        {__('Move to trash')}
                    </ResponsiveMenuItem>
                </ResponsiveMenuContent>
            </ResponsiveMenu>
            <p data-test="catalogue-overlay-menu-chosen" className="text-ink-2">
                {chosen ?? '—'}
            </p>
        </div>
    );
}

/**
 * The design system's catalogue (docs/architecture/design-system.md,
 * section 9.3): the tokens and every shared part in the states a still page
 * can hold. Only a local site and the tests can open it.
 */
export default function Catalogue() {
    const uses: Record<string, string> = {
        'text-caption': __('Help under a field, badges, group headings'),
        'text-small': __('Field labels, meta lines, pills and chips'),
        'text-body': __('Body text, table cells, controls and menu items'),
        'text-lead': __('Section headings, panel and dialog titles'),
        'text-title': __('Page titles and figure values'),
        'text-display': __('The post title in the editor'),
    };

    return (
        <>
            <Head title={__('Design catalogue')} />

            <div
                data-test="catalogue"
                className="grid min-w-0 gap-10 px-4 pt-2 pb-16 md:px-8"
            >
                <h1 className="text-title font-semibold">
                    {__('Design catalogue')}
                </h1>

                <Section
                    id="tokens"
                    title={__('Colours')}
                    description={__(
                        'Every colour token, with the words that may sit on it.',
                    )}
                >
                    {SWATCHES.map(({ group, swatches }) => (
                        <ul
                            key={group}
                            className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6"
                        >
                            {swatches.map(({ token, ground, ink }) => (
                                <li key={token} className="grid gap-2">
                                    <span
                                        aria-hidden="true"
                                        className={`shadow-raised flex h-16 items-center justify-center rounded-md ${ground} ${ink ?? ''}`}
                                    >
                                        {ink !== undefined && __('Aa')}
                                    </span>
                                    <code className="text-caption text-ink-2 font-mono">
                                        {token}
                                    </code>
                                </li>
                            ))}
                        </ul>
                    ))}
                </Section>

                <Section
                    id="type"
                    title={__('Type')}
                    description={__('The six sizes, and what each is for.')}
                >
                    <ul className="grid gap-4">
                        {SIZES.map(({ name, className }) => (
                            <li key={name} className="grid gap-1">
                                <span className={className}>
                                    {__('Ferries leave on the hour')}
                                </span>
                                <span className="text-small text-ink-3">
                                    <code className="font-mono">{name}</code>
                                    {' · '}
                                    {uses[name]}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    id="radii"
                    title={__('Corners')}
                    description={__(
                        'The six radii, from a checkbox to a pill.',
                    )}
                >
                    <ul className="flex flex-wrap gap-6">
                        {RADII.map(({ name, className }) => (
                            <li
                                key={name}
                                className="grid justify-items-center gap-2"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`bg-accent-soft-2 size-16 ${className}`}
                                />
                                <code className="text-caption text-ink-2 font-mono">
                                    {name}
                                </code>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    id="elevation"
                    title={__('Elevation')}
                    description={__(
                        'Raised for panels, floating for menus, modal for dialogs.',
                    )}
                >
                    <ul className="flex flex-wrap gap-8">
                        {SHADOWS.map(({ name, className }) => (
                            <li
                                key={name}
                                className="grid justify-items-center gap-2"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`bg-surface size-24 rounded-lg ${className}`}
                                />
                                <code className="text-caption text-ink-2 font-mono">
                                    {name}
                                </code>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    id="button"
                    title={__('Buttons')}
                    description={__(
                        'Every variant in three sizes, then icon buttons, and the states a button can hold.',
                    )}
                >
                    <div className="grid gap-3">
                        {SIZES_OF_BUTTONS.map((size) => (
                            <div
                                key={size}
                                className="flex flex-wrap items-center gap-3"
                            >
                                {VARIANTS.map((variant) => (
                                    <Button
                                        key={variant}
                                        variant={variant}
                                        size={size}
                                        data-test={
                                            variant === 'primary' &&
                                            size === 'md'
                                                ? 'catalogue-button-primary'
                                                : undefined
                                        }
                                    >
                                        {__('Publish')}
                                    </Button>
                                ))}
                            </div>
                        ))}
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                variant="neutral"
                                size="icon-md"
                                aria-label={__('Add')}
                            >
                                <PlusIcon />
                            </Button>
                            <Button
                                variant="neutral"
                                size="icon-sm"
                                aria-label={__('Add')}
                            >
                                <PlusIcon />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-xs"
                                aria-label={__('Add')}
                            >
                                <PlusIcon />
                            </Button>
                            <Button variant="link">
                                {__('Open the link report')}
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled>{__('Publish')}</Button>
                            <Button loading>{__('Publish')}</Button>
                            <Button variant="neutral" aria-pressed="true">
                                {__('Bold')}
                            </Button>
                            <div className="flex items-center gap-0.5">
                                <Button className="rounded-e-none">
                                    {__('Publish')}
                                </Button>
                                <Button
                                    size="icon"
                                    className="rounded-s-none"
                                    aria-label={__('More ways to publish')}
                                >
                                    <ChevronDownIcon />
                                </Button>
                            </div>
                        </div>
                    </div>
                </Section>

                <Section
                    id="textbutton"
                    title={__('Text buttons')}
                    description={__(
                        'Words that act in place, and a link drawn the same way.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-6">
                        <TextButton>{__('Show the history')}</TextButton>
                        <TextButton tone="danger">
                            {__('Remove the picture')}
                        </TextButton>
                        <TextButton asChild>
                            <a href="#textbutton">{__('Read the guide')}</a>
                        </TextButton>
                        <span className="text-small text-ink-2">
                            {__('Search')} <Kbd>/</Kbd>
                        </span>
                    </div>
                </Section>

                <Section
                    id="input"
                    title={__('Inputs')}
                    description={__(
                        'At rest, with a placeholder, in error, disabled, read-only, and for search.',
                    )}
                >
                    <div className="grid max-w-md gap-4">
                        <Field
                            htmlFor="catalogue-input-rest"
                            label={__('Title')}
                        >
                            <Input
                                id="catalogue-input-rest"
                                defaultValue={__('Ferries leave on the hour')}
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-input-placeholder"
                            label={__('Subtitle')}
                            help={__('Shown under the title on the site.')}
                        >
                            <Input
                                id="catalogue-input-placeholder"
                                placeholder={__('A line under the title')}
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-input-error"
                            label={__('Address')}
                            required
                            error={__(
                                'Another post already uses this address.',
                            )}
                        >
                            <Input
                                id="catalogue-input-error"
                                defaultValue="ferries"
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-input-disabled"
                            label={__('Author')}
                        >
                            <Input
                                id="catalogue-input-disabled"
                                defaultValue={__('Ada Admin')}
                                disabled
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-input-read-only"
                            label={__('Created')}
                        >
                            <Input
                                id="catalogue-input-read-only"
                                defaultValue={__('12 March')}
                                readOnly
                            />
                        </Field>
                        <div className="relative">
                            <SearchIcon
                                aria-hidden="true"
                                className="text-ink-3 pointer-events-none absolute start-3 top-1/2 size-4.5 -translate-y-1/2"
                            />
                            <Input
                                type="search"
                                className="ps-10"
                                aria-label={__('Search posts')}
                                placeholder={__('Search posts')}
                            />
                        </div>
                    </div>
                </Section>

                <Section
                    id="textarea"
                    title={__('Text areas')}
                    description={__(
                        'At rest, in error, disabled, and one that grows with its words.',
                    )}
                >
                    <div className="grid max-w-md gap-4">
                        <Field
                            htmlFor="catalogue-textarea-rest"
                            label={__('Excerpt')}
                        >
                            <Textarea id="catalogue-textarea-rest" />
                        </Field>
                        <Field
                            htmlFor="catalogue-textarea-error"
                            label={__('Description')}
                            error={__('Keep it under 160 characters.')}
                        >
                            <Textarea id="catalogue-textarea-error" />
                        </Field>
                        <Field
                            htmlFor="catalogue-textarea-disabled"
                            label={__('Notes')}
                        >
                            <Textarea
                                id="catalogue-textarea-disabled"
                                disabled
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-textarea-grow"
                            label={__('Caption')}
                        >
                            <Textarea
                                id="catalogue-textarea-grow"
                                autoGrow
                                rows={1}
                            />
                        </Field>
                    </div>
                </Section>

                <Section
                    id="select"
                    title={__('Selects')}
                    description={__(
                        'At rest, disabled, with nested choices, and a long list that scrolls.',
                    )}
                >
                    <div className="grid max-w-md gap-4">
                        <Field
                            htmlFor="catalogue-select-rest"
                            label={__('Category')}
                        >
                            <SelectField
                                id="catalogue-select-rest"
                                defaultValue="travel"
                                options={[
                                    { value: 'news', label: __('News') },
                                    { value: 'travel', label: __('Travel') },
                                    {
                                        value: 'ferries',
                                        label: __('Ferries'),
                                        depth: 1,
                                    },
                                    {
                                        value: 'islands',
                                        label: __('Islands'),
                                        depth: 2,
                                    },
                                ]}
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-select-disabled"
                            label={__('Language')}
                        >
                            <SelectField
                                id="catalogue-select-disabled"
                                defaultValue="en"
                                disabled
                                options={[
                                    { value: 'en', label: __('English') },
                                ]}
                            />
                        </Field>
                        <Field
                            htmlFor="catalogue-select-long"
                            label={__('Archive month')}
                        >
                            <SelectField
                                id="catalogue-select-long"
                                defaultValue="1"
                                options={Array.from(
                                    { length: 40 },
                                    (_, index) => ({
                                        value: String(index + 1),
                                        label: __('Month :number', {
                                            number: index + 1,
                                        }),
                                    }),
                                )}
                            />
                        </Field>
                    </div>
                </Section>

                <Section
                    id="combobox"
                    title={__('Comboboxes')}
                    description={__(
                        'One choice, several choices, an empty list, a search under way, disabled, and in error.',
                    )}
                >
                    <ComboboxDemo />
                </Section>

                <Section
                    id="checkbox"
                    title={__('Checkboxes')}
                    description={__(
                        'Unchecked, checked, some of them, and disabled.',
                    )}
                >
                    <div className="grid gap-2">
                        <FieldInline
                            htmlFor="catalogue-checkbox-off"
                            label={__('Allow comments')}
                        >
                            <Checkbox id="catalogue-checkbox-off" />
                        </FieldInline>
                        <FieldInline
                            htmlFor="catalogue-checkbox-on"
                            label={__('Show in the menu')}
                            help={__(
                                'Adds the page at the end of the main menu.',
                            )}
                        >
                            <Checkbox
                                id="catalogue-checkbox-on"
                                defaultChecked
                            />
                        </FieldInline>
                        <FieldInline
                            htmlFor="catalogue-checkbox-mixed"
                            label={__('All posts on this page')}
                        >
                            <Checkbox
                                id="catalogue-checkbox-mixed"
                                checked="indeterminate"
                            />
                        </FieldInline>
                        <FieldInline
                            htmlFor="catalogue-checkbox-disabled"
                            label={__('Sticky')}
                        >
                            <Checkbox
                                id="catalogue-checkbox-disabled"
                                disabled
                            />
                        </FieldInline>
                    </div>
                </Section>

                <Section
                    id="radio-group"
                    title={__('Radio groups')}
                    description={__(
                        'One choice at a time; the arrow keys move between them.',
                    )}
                >
                    <RadioGroup
                        defaultValue="never"
                        aria-label={__('Send a summary')}
                    >
                        <RadioGroupChoice
                            id="catalogue-radio-weekly"
                            data-test="catalogue-radio-weekly"
                            value="weekly"
                            label={__('Weekly')}
                            description={__('Every Monday morning.')}
                        />
                        <RadioGroupChoice
                            id="catalogue-radio-monthly"
                            data-test="catalogue-radio-monthly"
                            value="monthly"
                            label={__('Monthly')}
                            description={__('On the first of the month.')}
                        />
                        <RadioGroupChoice
                            id="catalogue-radio-never"
                            data-test="catalogue-radio-never"
                            value="never"
                            label={__('Never')}
                        />
                        <RadioGroupChoice
                            id="catalogue-radio-daily"
                            value="daily"
                            label={__('Daily')}
                            description={__('Only for administrators.')}
                            disabled
                        />
                    </RadioGroup>
                </Section>

                <Section
                    id="switch"
                    title={__('Switches')}
                    description={__('Off, on, and disabled.')}
                >
                    <div className="grid gap-3">
                        <FieldInline
                            htmlFor="catalogue-switch"
                            label={__('Send me a summary')}
                        >
                            <Switch
                                id="catalogue-switch"
                                data-test="catalogue-switch"
                            />
                        </FieldInline>
                        <FieldInline
                            htmlFor="catalogue-switch-on"
                            label={__('Show the word count')}
                        >
                            <Switch id="catalogue-switch-on" defaultChecked />
                        </FieldInline>
                        <FieldInline
                            htmlFor="catalogue-switch-disabled"
                            label={__('Keep a backup offsite')}
                        >
                            <Switch id="catalogue-switch-disabled" disabled />
                        </FieldInline>
                    </div>
                </Section>

                <Section
                    id="segmented"
                    title={__('Segmented controls')}
                    description={__(
                        'A few choices side by side, one always pressed.',
                    )}
                >
                    <Segmented
                        className="justify-self-start"
                        defaultValue="m"
                        aria-label={__('Tile size')}
                    >
                        <SegmentedItem
                            value="s"
                            data-test="catalogue-segment-s"
                        >
                            {__('S')}
                        </SegmentedItem>
                        <SegmentedItem
                            value="m"
                            data-test="catalogue-segment-m"
                        >
                            {__('M')}
                        </SegmentedItem>
                        <SegmentedItem
                            value="l"
                            data-test="catalogue-segment-l"
                        >
                            {__('L')}
                        </SegmentedItem>
                    </Segmented>
                </Section>

                <Section
                    id="input-otp"
                    title={__('Codes')}
                    description={__(
                        'Empty and filled; a wrong code marks every slot.',
                    )}
                >
                    <div className="grid gap-4">
                        <InputOTP maxLength={6} aria-label={__('Code')}>
                            <InputOTPGroup>
                                {[0, 1, 2, 3, 4, 5].map((index) => (
                                    <InputOTPSlot key={index} index={index} />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                        <InputOTP
                            maxLength={6}
                            defaultValue="4821"
                            aria-label={__('Code')}
                            aria-invalid="true"
                        >
                            <InputOTPGroup>
                                {[0, 1, 2, 3, 4, 5].map((index) => (
                                    <InputOTPSlot
                                        key={index}
                                        index={index}
                                        className="border-danger"
                                    />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                </Section>

                <Section
                    id="dialog"
                    title={__('Dialogs and sheets')}
                    description={__(
                        'A dialog at each of its four sizes, and a sheet from the end.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {DIALOG_SIZES.map((size) => (
                            <Dialog key={size}>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="neutral"
                                        data-test={`catalogue-dialog-${size}`}
                                    >
                                        {__('Open the :size dialog', { size })}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent size={size}>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {__('Publish this post?')}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {__(
                                                'It goes live at once, at its address.',
                                            )}
                                        </DialogDescription>
                                    </DialogHeader>
                                    {size === 'xl' ? (
                                        <ScrollArea
                                            className="flex-1"
                                            label={__('Revisions')}
                                        >
                                            {Array.from(
                                                { length: 30 },
                                                (_, index) => (
                                                    <p
                                                        key={index}
                                                        className="py-1"
                                                    >
                                                        {__(
                                                            'Revision :number',
                                                            {
                                                                number:
                                                                    index + 1,
                                                            },
                                                        )}
                                                    </p>
                                                ),
                                            )}
                                        </ScrollArea>
                                    ) : (
                                        <p>
                                            {__(
                                                'Readers who follow the site are told by email.',
                                            )}
                                        </p>
                                    )}
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button variant="neutral">
                                                {__('Cancel')}
                                            </Button>
                                        </DialogClose>
                                        <DialogClose asChild>
                                            <Button variant="primary">
                                                {__('Publish')}
                                            </Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        ))}
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button variant="neutral">
                                    {__('Open the sheet')}
                                </Button>
                            </SheetTrigger>
                            <SheetContent>
                                <SheetHeader>
                                    <SheetTitle>
                                        {__('Edit the redirect')}
                                    </SheetTitle>
                                    <SheetDescription>
                                        {__('Changes apply on the next visit.')}
                                    </SheetDescription>
                                </SheetHeader>
                                <SheetFooter>
                                    <Button variant="primary">
                                        {__('Save')}
                                    </Button>
                                </SheetFooter>
                            </SheetContent>
                        </Sheet>
                    </div>
                </Section>

                <Section
                    id="menu"
                    title={__('Menus, popovers and tooltips')}
                    description={__(
                        'A menu with a group label, a checkable item and a danger item; a popover; a tooltip.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="neutral">
                                    {__('Actions')}
                                    <ChevronDownIcon />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuLabel>
                                    {__('This post')}
                                </DropdownMenuLabel>
                                <DropdownMenuItem>
                                    <PencilIcon />
                                    {__('Edit')}
                                </DropdownMenuItem>
                                <DropdownMenuCheckboxItem checked>
                                    {__('Pinned')}
                                </DropdownMenuCheckboxItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive">
                                    <Trash2Icon />
                                    {__('Move to the bin')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Popover>
                            <PopoverTrigger asChild>
                                <Button variant="neutral">
                                    {__('Weather')}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent>
                                <p>
                                    {__('Sunny, a light wind from the west.')}
                                </p>
                            </PopoverContent>
                        </Popover>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={__('Add')}
                                >
                                    <PlusIcon />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{__('Add a block')}</TooltipContent>
                        </Tooltip>
                    </div>
                </Section>

                <Section
                    id="tabs"
                    title={__('Tabs')}
                    description={__(
                        'Panels of one screen; the arrow keys move between them.',
                    )}
                >
                    <Tabs defaultValue="library">
                        <TabsList aria-label={__('Add media')}>
                            <TabsTrigger
                                value="library"
                                count={128}
                                data-test="catalogue-tab-library"
                            >
                                {__('Library')}
                            </TabsTrigger>
                            <TabsTrigger
                                value="upload"
                                data-test="catalogue-tab-upload"
                            >
                                {__('Upload')}
                            </TabsTrigger>
                            <TabsTrigger value="plugins">
                                {__('From plugins')}
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="library">
                            {__('Every picture, file and video on the site.')}
                        </TabsContent>
                        <TabsContent value="upload">
                            {__('Drop files here, or choose them.')}
                        </TabsContent>
                        <TabsContent value="plugins">
                            {__('Sources that plugins add.')}
                        </TabsContent>
                    </Tabs>
                </Section>

                <Section
                    id="panel"
                    title={__('Panels and notices')}
                    description={__(
                        'A panel with its head and body, and the two notices.',
                    )}
                >
                    <div className="grid max-w-xl gap-4">
                        <Panel aria-labelledby="catalogue-panel-title">
                            <PanelHeader>
                                <h3 id="catalogue-panel-title">
                                    {__('Drafts')}
                                </h3>
                            </PanelHeader>
                            <PanelBody>
                                <p>
                                    {__('Three drafts wait for a second look.')}
                                </p>
                            </PanelBody>
                        </Panel>
                        <Alert>
                            <InfoIcon aria-hidden="true" />
                            <AlertTitle>{__('Scheduled')}</AlertTitle>
                            <AlertDescription>
                                {__('This post goes live on Friday at 9:00.')}
                            </AlertDescription>
                        </Alert>
                        <Alert variant="destructive">
                            <OctagonAlertIcon aria-hidden="true" />
                            <AlertTitle>{__('The backup failed')}</AlertTitle>
                            <AlertDescription>
                                {__(
                                    'The storage refused the file. Nothing was lost.',
                                )}
                            </AlertDescription>
                        </Alert>
                    </div>
                </Section>

                <Section
                    id="badge"
                    title={__('Badges, avatars and the trail')}
                    description={__(
                        'Each badge, a person’s initials, and the trail of pages above a screen.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {BADGES.map((variant) => (
                            <Badge key={variant} variant={variant}>
                                {12}
                            </Badge>
                        ))}
                        <Avatar>
                            <AvatarFallback>{__('AA')}</AvatarFallback>
                        </Avatar>
                    </div>
                    <Breadcrumb>
                        <BreadcrumbList>
                            <BreadcrumbItem>
                                <BreadcrumbLink href="#badge">
                                    {__('Posts')}
                                </BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator />
                            <BreadcrumbItem>
                                <BreadcrumbPage>
                                    {__('Ferries leave on the hour')}
                                </BreadcrumbPage>
                            </BreadcrumbItem>
                        </BreadcrumbList>
                    </Breadcrumb>
                    <Separator />
                </Section>

                <Section
                    id="disclosure"
                    title={__('Disclosures')}
                    description={__('Closed, and open.')}
                >
                    <div className="grid gap-2">
                        <Disclosure label={__('Advanced')} />
                        <Disclosure
                            label={__('Search and social')}
                            defaultOpen
                        />
                    </div>
                </Section>

                <Section
                    id="loading"
                    title={__('Loading and progress')}
                    description={__(
                        'Skeleton bars, the spinner, and progress at 0, 40 and 100 in each tone.',
                    )}
                >
                    <div className="grid max-w-md gap-4">
                        <div className="grid gap-2">
                            <Skeleton className="w-3/4" />
                            <Skeleton />
                            <Skeleton className="w-1/2" />
                        </div>
                        <Spinner />
                        {[0, 40, 100].map((value) => (
                            <Progress
                                key={value}
                                value={value}
                                label={__('Import, :value%', { value })}
                            />
                        ))}
                        {TONES.map((tone) => (
                            <Progress
                                key={tone}
                                value={60}
                                tone={tone}
                                label={__('Upload, :tone', { tone })}
                            />
                        ))}
                    </div>
                </Section>

                <Section
                    id="scroll-area"
                    title={__('Scrolling areas')}
                    description={__('A long list in a short area.')}
                >
                    <ScrollArea
                        className="bg-surface h-40 max-w-md rounded-md p-3"
                        label={__('Recent changes')}
                    >
                        <ul className="grid gap-1">
                            {Array.from({ length: 24 }, (_, index) => (
                                <li key={index}>
                                    {__('Change :number', {
                                        number: index + 1,
                                    })}
                                </li>
                            ))}
                        </ul>
                    </ScrollArea>
                </Section>

                <Section
                    id="status-pill"
                    title={__('Status pills')}
                    description={__(
                        'A state in a word, in each tone; flat; and as a tag that can be taken off.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusPill tone="ok">{__('Published')}</StatusPill>
                        <StatusPill tone="neutral">{__('Draft')}</StatusPill>
                        <StatusPill tone="info">{__('Scheduled')}</StatusPill>
                        <StatusPill tone="warn">
                            {__('Needs a look')}
                        </StatusPill>
                        <StatusPill tone="danger">{__('Problem')}</StatusPill>
                        <StatusPill tone="neutral" flat>
                            {__('Private')}
                        </StatusPill>
                        <StatusPill
                            tone="neutral"
                            onRemove={() => undefined}
                            removeLabel={__('Remove :name', {
                                name: __('Harbour'),
                            })}
                        >
                            {__('Harbour')}
                        </StatusPill>
                    </div>
                </Section>

                <Section
                    id="term-chips"
                    title={__('Term chips')}
                    description={__(
                        'Two terms when both fit the cell, the rest behind “+N”; none.',
                    )}
                >
                    <div className="grid gap-3">
                        <div className="w-48">
                            <TermChips
                                terms={[
                                    { id: 'transport', name: __('Transport') },
                                    { id: 'harbour', name: __('Harbour') },
                                    { id: 'ferries', name: __('Ferries') },
                                    { id: 'port', name: __('Port') },
                                    { id: 'weather', name: __('Weather') },
                                ]}
                                noun={{ one: __('tag'), many: __('tags') }}
                                emptyLabel={__('No tags')}
                            />
                        </div>
                        <div className="w-48">
                            <TermChips
                                terms={[]}
                                noun={{ one: __('tag'), many: __('tags') }}
                                emptyLabel={__('No tags')}
                            />
                        </div>
                    </div>
                </Section>

                <Section
                    id="soon-tag"
                    title={__('Soon tags')}
                    description={__('After something planned and not built.')}
                >
                    <p className="text-body">
                        {__('Newsletter')}
                        <SoonTag />
                    </p>
                </Section>

                <Section
                    id="plugin-mark"
                    title={__('Plugin marks')}
                    description={__(
                        'Where a plugin adds something: with its name, and as an icon alone.',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <PluginMark name={__('Event calendar')} />
                        <PluginMark name={__('Event calendar')} iconOnly />
                    </div>
                </Section>

                <Section
                    id="plugin-icon"
                    title={__('Plugin icons')}
                    description={__(
                        'At 32, 56, 64 and 96 pixels: the initials when there is no picture.',
                    )}
                >
                    <div className="flex flex-wrap items-end gap-4">
                        {([32, 56, 64, 96] as const).map((size) => (
                            <PluginIcon
                                key={size}
                                name={__('Event calendar')}
                                size={size}
                            />
                        ))}
                    </div>
                </Section>

                <Section
                    id="plugin-area"
                    title={__('A plugin’s own area')}
                    description={__(
                        'The plugin’s icon and mark at the head, its pages under it, and one sentence when a page fails.',
                    )}
                >
                    <PluginAreaDemo />
                </Section>

                <Section
                    id="icon-tile"
                    title={__('Icon tiles')}
                    description={__('Small, medium and large.')}
                >
                    <div className="flex flex-wrap items-end gap-4">
                        <IconTile icon={Calendar} size="sm" />
                        <IconTile icon={ImageIcon} />
                        <IconTile icon={Wrench} size="lg" />
                    </div>
                </Section>

                <Section
                    id="person-picture"
                    title={__('Pictures of people')}
                    description={__(
                        'Initials at 24, 40 and 96 pixels, and someone invited.',
                    )}
                >
                    <div className="flex flex-wrap items-end gap-4">
                        {([24, 40, 96] as const).map((size) => (
                            <PersonPicture
                                key={size}
                                name="Ada Lovelace"
                                size={size}
                            />
                        ))}
                        <PersonPicture name="Grace Hopper" size={40} invited />
                    </div>
                </Section>

                <Section
                    id="step-bar"
                    title={__('Step bars')}
                    description={__(
                        'Where a person is in a task of steps: the second of three, and all done.',
                    )}
                >
                    <div className="grid gap-4">
                        <StepBar
                            steps={[
                                __('Choose the file'),
                                __('Match the fields'),
                                __('Import'),
                            ]}
                            current={1}
                        />
                        <StepBar
                            steps={[
                                __('Choose the file'),
                                __('Match the fields'),
                                __('Import'),
                            ]}
                            current={3}
                        />
                    </div>
                </Section>

                <Section
                    id="states"
                    title={__('Empty, loading and failed')}
                    description={__(
                        'Nothing yet; a skeleton after 300 milliseconds; a failure with a way to try again.',
                    )}
                >
                    <EmptyState
                        kind="nothing-yet"
                        title={__('No posts yet')}
                        description={__('Posts you write show here.')}
                    />
                    <StatesDemo />
                </Section>

                <Section
                    id="notice"
                    title={__('Notices')}
                    description={__(
                        'Read in place, in each tone: with a title, an action and a dismiss.',
                    )}
                >
                    <div className="grid max-w-2xl gap-3">
                        <Notice tone="info" title={__('Before you import')}>
                            {__('Posts keep their addresses when they arrive.')}
                        </Notice>
                        <Notice tone="ok">{__('The import finished.')}</Notice>
                        <Notice
                            tone="warn"
                            action={
                                <Button variant="neutral" size="sm">
                                    {__('Open Themes')}
                                </Button>
                            }
                        >
                            {__('The theme in use has an update.')}
                        </Notice>
                        <Notice tone="danger" onDismiss={() => undefined}>
                            {__('The backup couldn’t be written.')}
                        </Notice>
                    </div>
                </Section>

                <Section
                    id="toast"
                    title={__('Toasts')}
                    description={__(
                        'At the foot of the screen, in the middle: a success, and a failure.',
                    )}
                >
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={() =>
                                showToast({
                                    type: 'success',
                                    message: __('Post published.'),
                                })
                            }
                        >
                            {__('Show a toast')}
                        </Button>
                        <Button
                            type="button"
                            variant="neutral"
                            onClick={() =>
                                showToast({
                                    type: 'error',
                                    message: __('The post couldn’t be saved.'),
                                })
                            }
                        >
                            {__('Show a failure')}
                        </Button>
                    </div>
                </Section>

                <Section
                    id="save-bar"
                    title={__('Save bar')}
                    description={__(
                        'Shown once something changes; Discard puts the fields back.',
                    )}
                >
                    <SaveBarDemo />
                </Section>

                <Section
                    id="fresh-password"
                    title={__('Fresh password')}
                    description={__(
                        'The password asked for before a sensitive act.',
                    )}
                >
                    <FreshPasswordDemo />
                </Section>

                <Section
                    id="media-picker"
                    title={__('Media picker')}
                    description={__(
                        'The one picker, as each screen opens it: the title and main button follow the caller.',
                    )}
                >
                    <MediaPickerDemo />
                </Section>

                <Section
                    id="patterns"
                    title={__('Page patterns')}
                    description={__(
                        'Every admin page is one of these: a list, a form, settings, a hub or a detail page.',
                    )}
                >
                    <InPageNav
                        label={__('Posts by status')}
                        items={[
                            {
                                href: '#patterns',
                                label: __('All'),
                                count: 48,
                                current: true,
                            },
                            {
                                href: '#patterns',
                                label: __('Published'),
                                count: 40,
                            },
                            {
                                href: '#patterns',
                                label: __('Drafts'),
                                count: 6,
                            },
                            {
                                href: '#patterns',
                                label: __('Scheduled'),
                                count: 2,
                            },
                            { href: '#patterns', label: __('Trash') },
                            {
                                href: '#patterns',
                                label: __('Pending review'),
                                soon: true,
                            },
                        ]}
                    />
                    <PatternFrame name="list">
                        <ListPage
                            title={__('Posts')}
                            description={__('Everything written for the site.')}
                            actions={<Button>{__('New post')}</Button>}
                            titleAs="div"
                        >
                            <p className="text-ink-2">
                                {__('The table of posts.')}
                            </p>
                        </ListPage>
                    </PatternFrame>
                    <PatternFrame name="form">
                        <FormPage
                            title={__('New place')}
                            description={__('Where backups are kept.')}
                            titleAs="div"
                        >
                            <FormPanel title={__('Place')}>
                                <Field
                                    label={__('Name')}
                                    htmlFor="catalogue-pattern-name"
                                >
                                    <Input id="catalogue-pattern-name" />
                                </Field>
                            </FormPanel>
                        </FormPage>
                    </PatternFrame>
                    <PatternFrame name="settings">
                        <SettingsPage
                            title={__('Languages')}
                            description={__(
                                'The languages the site is written in.',
                            )}
                            titleAs="div"
                        >
                            <p className="text-ink-2">
                                {__('The settings’ panels.')}
                            </p>
                        </SettingsPage>
                    </PatternFrame>
                    <PatternFrame name="hub">
                        <HubPage
                            title={__('Tools')}
                            description={__(
                                'Imports, backups and the site’s upkeep.',
                            )}
                            titleAs="div"
                            cards={[
                                <HubCard
                                    key="import"
                                    href="#patterns"
                                    icon={Wrench}
                                    title={__('Import')}
                                    description={__(
                                        'Bring posts in from another site.',
                                    )}
                                />,
                                <HubCard
                                    key="calendar"
                                    href="#patterns"
                                    icon={Calendar}
                                    title={__('Events')}
                                    description={__(
                                        'Dates to show on the site.',
                                    )}
                                    plugin={__('Event calendar')}
                                />,
                            ]}
                        />
                    </PatternFrame>
                    <PatternFrame name="detail">
                        <DetailPage
                            title={__('Harbour opening hours')}
                            description={__('A page, published.')}
                            titleAs="div"
                            panel={
                                <p className="text-ink-2">
                                    {__('Its details.')}
                                </p>
                            }
                            panelLabel={__('Details')}
                        >
                            <p className="text-ink-2">
                                {__('The page’s content.')}
                            </p>
                        </DetailPage>
                    </PatternFrame>
                </Section>

                <Section
                    id="phone-overlays"
                    title={__('Phone overlays')}
                    description={__(
                        'Below 640 pixels: a dialog fills the screen, a confirmation and a menu open from its foot, and a side sheet fills it.',
                    )}
                >
                    <PhoneOverlaysDemo />
                </Section>
            </div>
        </>
    );
}
