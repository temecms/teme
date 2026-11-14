<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Fields\Types\BooleanField;
use App\Domain\Fields\Types\CanonicalField;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Fields\Types\NumberField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Fields\Types\TermField;
use App\Domain\Fields\Types\TextareaField;
use App\Domain\Fields\Types\TextField;
use App\Domain\StructuredData\TypeSchemas;

/**
 * The fields every post and page has from the start: what search engines
 * and social cards read and what they are told the item is (I-147, I-292,
 * I-293, I-714), the category that stands for them (I-295), the image that
 * stands for them, the theme template they are drawn with (I-443), and, for
 * posts, whether they are pinned (I-156). Their keys never change.
 */
final class BuiltInFields
{
    public const string SEO_TITLE = '01K5Z0F1ED00000000000000S1';

    public const string META_DESCRIPTION = '01K5Z0F1ED00000000000000D2';

    public const string EXCERPT = '01K5Z0F1ED00000000000000E3';

    public const string CANONICAL = '01K5Z0F1ED00000000000000C4';

    public const string NOINDEX = '01K5Z0F1ED00000000000000N5';

    public const string FEATURED_IMAGE = '01K5Z0F1ED00000000000000F6';

    public const string TEMPLATE = '01K5Z0F1ED00000000000000T7';

    public const string STICKY = '01K5Z0F1ED00000000000000P8';

    public const string SOCIAL_TITLE = '01K5Z0F1ED00000000000000ST';

    public const string SOCIAL_DESCRIPTION = '01K5Z0F1ED00000000000000SD';

    public const string SOCIAL_IMAGE = '01K5Z0F1ED00000000000000SG';

    public const string NOSNIPPET = '01K5Z0F1ED00000000000000NS';

    public const string MAX_SNIPPET = '01K5Z0F1ED00000000000000MS';

    public const string NOARCHIVE = '01K5Z0F1ED00000000000000NA';

    public const string PRIMARY_CATEGORY = '01K5Z0F1ED00000000000000PC';

    public const string PAGE_TYPE = '01K5Z0F1ED00000000000000PT';

    public const string ARTICLE_TYPE = '01K5Z0F1ED00000000000000AT';

    /**
     * No field names a type: each applies to every type that supports its
     * feature, so a type registered later gets them by declaring it.
     */
    public static function register(FieldRegistry $registry): void
    {
        $registry->register(new Field(self::SEO_TITLE, 'seo_title', 'SEO title', 'The title search engines show. Leave empty to use the item\'s title.', new TextField, [], '0.1.0', maxLength: 200, feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::META_DESCRIPTION, 'meta_description', 'Meta description', 'The summary search engines may show under the title.', new TextareaField, [], '0.1.0', maxLength: 320, feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::EXCERPT, 'excerpt', 'Excerpt', 'A short summary, shown in lists and feeds where themes choose.', new TextareaField, [], '0.1.0', maxLength: 1000, feature: 'excerpt', place: FieldPlace::Core));
        $registry->register(new Field(self::CANONICAL, 'canonical', 'Canonical address', 'Where search engines should send readers for this content: empty for this item, another item, or the address on another site where it was first published.', new CanonicalField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::NOINDEX, 'noindex', 'Hide from search engines', 'Asks search engines not to list this item.', new BooleanField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::FEATURED_IMAGE, 'featured_image', 'Featured image', 'The image that stands for this item in lists, feeds and social cards.', new MediaField(['image'], allowsDecorative: false), [], '0.1.0', feature: 'featured_image', place: FieldPlace::Core));
        // Empty uses the theme's default; TemplateChoice checks a new choice against the active theme (I-443).
        $registry->register(new Field(self::TEMPLATE, 'template', 'Template', 'The layout this item uses, from the templates the active theme offers for its type. Empty uses the theme\'s default.', new TextField, [], '0.1.0', maxLength: 60, feature: 'template', place: FieldPlace::Core));
        // The latest posts lead with pinned items (I-156).
        $registry->register(new Field(self::STICKY, 'sticky', 'Pin to the top of the latest posts', 'Shows this item first in the latest posts, ahead of newer ones, until it is unpinned.', new BooleanField, [], '0.1.0', feature: 'sticky', place: FieldPlace::Core));
        // Search and social's card, its snippet controls and the primary category (I-293, I-714, I-295).
        $registry->register(new Field(self::SOCIAL_TITLE, 'social_title', 'Social title', 'The title on the card when this is shared. Empty uses the SEO title, or else the title.', new TextField, [], '0.1.0', maxLength: 200, feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::SOCIAL_DESCRIPTION, 'social_description', 'Social description', 'The text on the card when this is shared. Empty uses the meta description, or else the excerpt.', new TextareaField, [], '0.1.0', maxLength: 320, feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::SOCIAL_IMAGE, 'social_image', 'Social image', 'The image on the card when this is shared. Empty uses the featured image, or else the first image in the body.', new MediaField(['image'], allowsDecorative: false), [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::NOSNIPPET, 'nosnippet', 'Don’t show a snippet', 'Asks search engines to show no text from this in results or in AI answers. The ordinary snippet under its title goes too.', new BooleanField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::MAX_SNIPPET, 'max_snippet', 'Snippet length', 'The most characters search engines may quote from this in results and AI answers. Empty sets no limit.', new NumberField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::NOARCHIVE, 'noarchive', 'Don’t keep a cached copy', 'Asks search engines not to keep or show a copy of this. Bing also leaves such pages out of its AI answers.', new BooleanField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::PRIMARY_CATEGORY, 'primary_category', 'Primary category', 'The category that stands for this when it has several. Empty uses the first one ticked.', new TermField, [], '0.1.0', feature: 'seo', place: FieldPlace::Core));
        // What search engines are told the item is, from closed lists, by whoever may edit it (I-709).
        $registry->register(new Field(self::PAGE_TYPE, 'page_type', 'Page type', 'What search engines are told this page is. Empty uses the default for its type.', new SelectField, [], '0.1.0', options: ['' => 'Default for its type', 'WebPage' => 'Web page', 'AboutPage' => 'About page', 'ContactPage' => 'Contact page', 'CollectionPage' => 'Collection page'], feature: 'seo', place: FieldPlace::Core));
        $registry->register(new Field(self::ARTICLE_TYPE, 'article_type', 'Article type', 'What kind of article search engines are told this is, if it is one. Empty uses the default for its type.', new SelectField, [], '0.1.0', options: ['' => 'Default for its type', 'Article' => 'Article', 'NewsArticle' => 'News article', 'BlogPosting' => 'Blog post', TypeSchemas::NONE => 'Not an article'], feature: 'seo', place: FieldPlace::Core));
    }
}
