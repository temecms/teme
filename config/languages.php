<?php

declare(strict_types=1);

/*
 * The languages a site may be written in (I-435): each canonical BCP 47 code
 * (the language lowercase, the region uppercase) with its name, and the
 * PostgreSQL text-search configuration that stems its words. A language
 * PostgreSQL 18 has no configuration for maps to `simple`: exact words, no
 * stemming. Only configurations `pg_ts_config` really lists are named here,
 * and a test holds them to it. Each also has its own name (`native`), the
 * direction its text runs (`direction`) and Open Graph's locale for it
 * (`og`, from Facebook's list; I-3360).
 */
return [
    'en' => ['label' => 'English', 'search' => 'english', 'native' => 'English', 'direction' => 'ltr', 'og' => 'en_US'],
    'en-GB' => ['label' => 'English (United Kingdom)', 'search' => 'english', 'native' => 'English (United Kingdom)', 'direction' => 'ltr', 'og' => 'en_GB'],
    'en-NG' => ['label' => 'English (Nigeria)', 'search' => 'english', 'native' => 'English (Nigeria)', 'direction' => 'ltr', 'og' => 'en_GB'],
    'en-US' => ['label' => 'English (United States)', 'search' => 'english', 'native' => 'English (United States)', 'direction' => 'ltr', 'og' => 'en_US'],
    'ar' => ['label' => 'Arabic', 'search' => 'arabic', 'native' => 'العربية', 'direction' => 'rtl', 'og' => 'ar_AR'],
    'ca' => ['label' => 'Catalan', 'search' => 'catalan', 'native' => 'Català', 'direction' => 'ltr', 'og' => 'ca_ES'],
    'cs' => ['label' => 'Czech', 'search' => 'simple', 'native' => 'Čeština', 'direction' => 'ltr', 'og' => 'cs_CZ'],
    'da' => ['label' => 'Danish', 'search' => 'danish', 'native' => 'Dansk', 'direction' => 'ltr', 'og' => 'da_DK'],
    'de' => ['label' => 'German', 'search' => 'german', 'native' => 'Deutsch', 'direction' => 'ltr', 'og' => 'de_DE'],
    'el' => ['label' => 'Greek', 'search' => 'greek', 'native' => 'Ελληνικά', 'direction' => 'ltr', 'og' => 'el_GR'],
    'es' => ['label' => 'Spanish', 'search' => 'spanish', 'native' => 'Español', 'direction' => 'ltr', 'og' => 'es_ES'],
    'et' => ['label' => 'Estonian', 'search' => 'estonian', 'native' => 'Eesti', 'direction' => 'ltr', 'og' => 'et_EE'],
    'eu' => ['label' => 'Basque', 'search' => 'basque', 'native' => 'Euskara', 'direction' => 'ltr', 'og' => 'eu_ES'],
    'fi' => ['label' => 'Finnish', 'search' => 'finnish', 'native' => 'Suomi', 'direction' => 'ltr', 'og' => 'fi_FI'],
    'fr' => ['label' => 'French', 'search' => 'french', 'native' => 'Français', 'direction' => 'ltr', 'og' => 'fr_FR'],
    'ga' => ['label' => 'Irish', 'search' => 'irish', 'native' => 'Gaeilge', 'direction' => 'ltr', 'og' => 'ga_IE'],
    'ha' => ['label' => 'Hausa', 'search' => 'simple', 'native' => 'Hausa', 'direction' => 'ltr', 'og' => 'ha_NG'],
    'hi' => ['label' => 'Hindi', 'search' => 'hindi', 'native' => 'हिन्दी', 'direction' => 'ltr', 'og' => 'hi_IN'],
    'hu' => ['label' => 'Hungarian', 'search' => 'hungarian', 'native' => 'Magyar', 'direction' => 'ltr', 'og' => 'hu_HU'],
    'hy' => ['label' => 'Armenian', 'search' => 'armenian', 'native' => 'Հայերեն', 'direction' => 'ltr', 'og' => 'hy_AM'],
    'id' => ['label' => 'Indonesian', 'search' => 'indonesian', 'native' => 'Bahasa Indonesia', 'direction' => 'ltr', 'og' => 'id_ID'],
    'ig' => ['label' => 'Igbo', 'search' => 'simple', 'native' => 'Igbo', 'direction' => 'ltr', 'og' => 'ig_NG'],
    'it' => ['label' => 'Italian', 'search' => 'italian', 'native' => 'Italiano', 'direction' => 'ltr', 'og' => 'it_IT'],
    'ja' => ['label' => 'Japanese', 'search' => 'simple', 'native' => '日本語', 'direction' => 'ltr', 'og' => 'ja_JP'],
    'lt' => ['label' => 'Lithuanian', 'search' => 'lithuanian', 'native' => 'Lietuvių', 'direction' => 'ltr', 'og' => 'lt_LT'],
    'nb' => ['label' => 'Norwegian Bokmål', 'search' => 'norwegian', 'native' => 'Norsk bokmål', 'direction' => 'ltr', 'og' => 'nb_NO'],
    'ne' => ['label' => 'Nepali', 'search' => 'nepali', 'native' => 'नेपाली', 'direction' => 'ltr', 'og' => 'ne_NP'],
    'nl' => ['label' => 'Dutch', 'search' => 'dutch', 'native' => 'Nederlands', 'direction' => 'ltr', 'og' => 'nl_NL'],
    'pcm' => ['label' => 'Nigerian Pidgin', 'search' => 'simple', 'native' => 'Naijá', 'direction' => 'ltr', 'og' => 'pcm_NG'],
    'pl' => ['label' => 'Polish', 'search' => 'simple', 'native' => 'Polski', 'direction' => 'ltr', 'og' => 'pl_PL'],
    'pt' => ['label' => 'Portuguese', 'search' => 'portuguese', 'native' => 'Português', 'direction' => 'ltr', 'og' => 'pt_PT'],
    'pt-BR' => ['label' => 'Portuguese (Brazil)', 'search' => 'portuguese', 'native' => 'Português (Brasil)', 'direction' => 'ltr', 'og' => 'pt_BR'],
    'ro' => ['label' => 'Romanian', 'search' => 'romanian', 'native' => 'Română', 'direction' => 'ltr', 'og' => 'ro_RO'],
    'ru' => ['label' => 'Russian', 'search' => 'russian', 'native' => 'Русский', 'direction' => 'ltr', 'og' => 'ru_RU'],
    'sr' => ['label' => 'Serbian', 'search' => 'serbian', 'native' => 'Српски', 'direction' => 'ltr', 'og' => 'sr_RS'],
    'sv' => ['label' => 'Swedish', 'search' => 'swedish', 'native' => 'Svenska', 'direction' => 'ltr', 'og' => 'sv_SE'],
    'sw' => ['label' => 'Swahili', 'search' => 'simple', 'native' => 'Kiswahili', 'direction' => 'ltr', 'og' => 'sw_KE'],
    'ta' => ['label' => 'Tamil', 'search' => 'tamil', 'native' => 'தமிழ்', 'direction' => 'ltr', 'og' => 'ta_IN'],
    'tr' => ['label' => 'Turkish', 'search' => 'turkish', 'native' => 'Türkçe', 'direction' => 'ltr', 'og' => 'tr_TR'],
    'uk' => ['label' => 'Ukrainian', 'search' => 'simple', 'native' => 'Українська', 'direction' => 'ltr', 'og' => 'uk_UA'],
    'yi' => ['label' => 'Yiddish', 'search' => 'yiddish', 'native' => 'ייִדיש', 'direction' => 'rtl', 'og' => 'yi_DE'],
    'yo' => ['label' => 'Yoruba', 'search' => 'simple', 'native' => 'Yorùbá', 'direction' => 'ltr', 'og' => 'yo_NG'],
];
