<?php

/**
 * DemoCatalog.
 *
 * Sample content for `ecommerce:seed-demo` (parent plan §15.5) in the four
 * day-1 locales — `en`, `es`, `fr`, `de` (§16.5). Everything here is demo
 * *data*, not interface copy, so none of it runs through `__()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Demo;

/**
 * Static, locale-keyed sample content for the demo seeder.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class DemoCatalog
{
    /**
     * Locales the demo content covers.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const LOCALES = [ 'en', 'es', 'fr', 'de' ];

    /**
     * Store market per locale: countries shoppers ship to, the currency
     * their orders are placed in, and a phone prefix.
     *
     * @since 1.0.0
     *
     * @var array<string, array{countries: array<int, string>, currency: string, phone: string}>
     */
    public const MARKETS = [
        'en' => [ 'countries' => [ 'US', 'US', 'US', 'GB' ], 'currency' => 'USD', 'phone' => '+1' ],
        'es' => [ 'countries' => [ 'ES' ], 'currency' => 'EUR', 'phone' => '+34' ],
        'fr' => [ 'countries' => [ 'FR' ], 'currency' => 'EUR', 'phone' => '+33' ],
        'de' => [ 'countries' => [ 'DE' ], 'currency' => 'EUR', 'phone' => '+49' ],
    ];

    /**
     * Collection names appended to product names to keep them unique once
     * the base names run out.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const COLLECTIONS = [ 'Nord', 'Sol', 'Lumen', 'Terra', 'Aura', 'Brisa', 'Atlas', 'Vela' ];

    /**
     * Product categories: the product type each one seeds, and its label
     * per locale. Categories are stored in `products.meta.categories` —
     * the engine has no categories table yet.
     *
     * @since 1.0.0
     *
     * @var array<string, array{kind: string, labels: array<string, string>}>
     */
    public const CATEGORIES = [
        'apparel'  => [
            'kind'   => 'variable',
            'labels' => [ 'en' => 'Apparel', 'es' => 'Ropa', 'fr' => 'Vêtements', 'de' => 'Bekleidung' ],
        ],
        'home'     => [
            'kind'   => 'simple',
            'labels' => [ 'en' => 'Home', 'es' => 'Hogar', 'fr' => 'Maison', 'de' => 'Wohnen' ],
        ],
        'kitchen'  => [
            'kind'   => 'simple',
            'labels' => [ 'en' => 'Kitchen', 'es' => 'Cocina', 'fr' => 'Cuisine', 'de' => 'Küche' ],
        ],
        'ebooks'   => [
            'kind'   => 'digital',
            'labels' => [ 'en' => 'E-books', 'es' => 'Libros electrónicos', 'fr' => 'Livres numériques', 'de' => 'E-Books' ],
        ],
        'software' => [
            'kind'   => 'digital',
            'labels' => [ 'en' => 'Software', 'es' => 'Software', 'fr' => 'Logiciels', 'de' => 'Software' ],
        ],
        'music'    => [
            'kind'   => 'digital',
            'labels' => [ 'en' => 'Music', 'es' => 'Música', 'fr' => 'Musique', 'de' => 'Musik' ],
        ],
    ];

    /**
     * Base product names per category and locale.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public const PRODUCT_NAMES = [
        'apparel'  => [
            'en' => [ 'Organic Cotton Tee', 'Merino Wool Sweater', 'Linen Shirt', 'Rain Jacket', 'Everyday Hoodie' ],
            'es' => [ 'Camiseta de algodón orgánico', 'Jersey de lana merino', 'Camisa de lino', 'Chubasquero', 'Sudadera diaria' ],
            'fr' => [ 'T-shirt en coton bio', 'Pull en laine mérinos', 'Chemise en lin', 'Veste de pluie', 'Sweat du quotidien' ],
            'de' => [ 'Bio-Baumwoll-T-Shirt', 'Merinowollpullover', 'Leinenhemd', 'Regenjacke', 'Alltags-Hoodie' ],
        ],
        'home'     => [
            'en' => [ 'Ceramic Table Lamp', 'Wool Throw Blanket', 'Oak Picture Frame', 'Scented Soy Candle', 'Linen Cushion Cover' ],
            'es' => [ 'Lámpara de mesa de cerámica', 'Manta de lana', 'Marco de roble', 'Vela de soja aromática', 'Funda de cojín de lino' ],
            'fr' => [ 'Lampe de table en céramique', 'Plaid en laine', 'Cadre en chêne', 'Bougie parfumée au soja', 'Housse de coussin en lin' ],
            'de' => [ 'Keramik-Tischlampe', 'Wolldecke', 'Eichen-Bilderrahmen', 'Duftkerze aus Soja', 'Leinen-Kissenbezug' ],
        ],
        'kitchen'  => [
            'en' => [ 'Cast Iron Skillet', 'Pour-Over Coffee Set', 'Olive Wood Cutting Board', 'Stoneware Mug', 'Chef\'s Knife' ],
            'es' => [ 'Sartén de hierro fundido', 'Set de café de goteo', 'Tabla de olivo', 'Taza de gres', 'Cuchillo de chef' ],
            'fr' => [ 'Poêle en fonte', 'Kit café filtre', 'Planche en bois d\'olivier', 'Mug en grès', 'Couteau de chef' ],
            'de' => [ 'Gusseisenpfanne', 'Handfilter-Kaffeeset', 'Olivenholz-Schneidebrett', 'Steingut-Becher', 'Kochmesser' ],
        ],
        'ebooks'   => [
            'en' => [ 'The Slow Kitchen', 'Field Notes on Design', 'A Year of Bread', 'Small Space Living' ],
            'es' => [ 'La cocina lenta', 'Apuntes de diseño', 'Un año de pan', 'Vivir en poco espacio' ],
            'fr' => [ 'La cuisine lente', 'Carnets de design', 'Une année de pain', 'Vivre petit' ],
            'de' => [ 'Die langsame Küche', 'Notizen zum Design', 'Ein Jahr Brot', 'Wohnen auf kleinem Raum' ],
        ],
        'software' => [
            'en' => [ 'Invoice Studio', 'Photo Batch Pro', 'Focus Timer' ],
            'es' => [ 'Estudio de facturas', 'Lote de fotos Pro', 'Temporizador de enfoque' ],
            'fr' => [ 'Studio de factures', 'Photo Lot Pro', 'Minuteur de concentration' ],
            'de' => [ 'Rechnungsstudio', 'Fotostapel Pro', 'Fokus-Timer' ],
        ],
        'music'    => [
            'en' => [ 'Morning Sessions (Album)', 'Lo-fi Study Beats', 'Acoustic Evenings' ],
            'es' => [ 'Sesiones matinales (álbum)', 'Ritmos lo-fi para estudiar', 'Tardes acústicas' ],
            'fr' => [ 'Sessions du matin (album)', 'Beats lo-fi pour étudier', 'Soirées acoustiques' ],
            'de' => [ 'Morgensessions (Album)', 'Lo-fi-Lernbeats', 'Akustische Abende' ],
        ],
    ];

    /**
     * Product descriptions per category and locale. `:name` is replaced
     * with the product name.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, string>>
     */
    public const DESCRIPTIONS = [
        'apparel'  => [
            'en' => ':name, cut from responsibly sourced fabric and made to last season after season.',
            'es' => ':name, confeccionada con tejidos de origen responsable y pensada para durar temporada tras temporada.',
            'fr' => ':name, taillé dans une matière d\'origine responsable et conçu pour durer saison après saison.',
            'de' => ':name, aus verantwortungsvoll bezogenem Stoff gefertigt und für viele Saisons gemacht.',
        ],
        'home'     => [
            'en' => ':name that brings warmth and a little calm to any room.',
            'es' => ':name que aporta calidez y un poco de calma a cualquier habitación.',
            'fr' => ':name qui apporte chaleur et sérénité à chaque pièce.',
            'de' => ':name, das jedem Raum Wärme und etwas Ruhe verleiht.',
        ],
        'kitchen'  => [
            'en' => ':name built for daily cooking and years of use.',
            'es' => ':name pensado para cocinar a diario durante años.',
            'fr' => ':name conçu pour la cuisine de tous les jours, pendant des années.',
            'de' => ':name für das tägliche Kochen und jahrelangen Gebrauch.',
        ],
        'ebooks'   => [
            'en' => 'Download :name instantly as PDF and EPUB.',
            'es' => 'Descarga :name al instante en PDF y EPUB.',
            'fr' => 'Téléchargez :name immédiatement en PDF et EPUB.',
            'de' => 'Laden Sie :name sofort als PDF und EPUB herunter.',
        ],
        'software' => [
            'en' => ':name for macOS and Windows. Includes a license key and a year of updates.',
            'es' => ':name para macOS y Windows. Incluye clave de licencia y un año de actualizaciones.',
            'fr' => ':name pour macOS et Windows. Clé de licence et un an de mises à jour inclus.',
            'de' => ':name für macOS und Windows. Inklusive Lizenzschlüssel und einem Jahr Updates.',
        ],
        'music'    => [
            'en' => ':name in lossless FLAC and high-quality MP3.',
            'es' => ':name en FLAC sin pérdida y MP3 de alta calidad.',
            'fr' => ':name en FLAC sans perte et MP3 haute qualité.',
            'de' => ':name als verlustfreies FLAC und hochwertiges MP3.',
        ],
    ];

    /**
     * Variation attributes for variable (apparel) products: key, label per
     * locale, and values (`value` => label per locale).
     *
     * @since 1.0.0
     *
     * @var array<string, array{labels: array<string, string>, values: array<string, array<string, string>>}>
     */
    public const ATTRIBUTES = [
        'size'  => [
            'labels' => [ 'en' => 'Size', 'es' => 'Talla', 'fr' => 'Taille', 'de' => 'Größe' ],
            'values' => [
                's' => [ 'en' => 'S', 'es' => 'S', 'fr' => 'S', 'de' => 'S' ],
                'm' => [ 'en' => 'M', 'es' => 'M', 'fr' => 'M', 'de' => 'M' ],
                'l' => [ 'en' => 'L', 'es' => 'L', 'fr' => 'L', 'de' => 'L' ],
            ],
        ],
        'color' => [
            'labels' => [ 'en' => 'Color', 'es' => 'Color', 'fr' => 'Couleur', 'de' => 'Farbe' ],
            'values' => [
                'navy'  => [ 'en' => 'Navy', 'es' => 'Azul marino', 'fr' => 'Bleu marine', 'de' => 'Marineblau' ],
                'sand'  => [ 'en' => 'Sand', 'es' => 'Arena', 'fr' => 'Sable', 'de' => 'Sand' ],
                'olive' => [ 'en' => 'Olive', 'es' => 'Oliva', 'fr' => 'Olive', 'de' => 'Oliv' ],
            ],
        ],
    ];

    /**
     * Swatch colors for the `color` attribute values.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const SWATCHES = [ 'navy' => '#1E3A5F', 'sand' => '#D8C3A5', 'olive' => '#6B7B3A' ];

    /**
     * Shopper first names per locale.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const FIRST_NAMES = [
        'en' => [ 'Emma', 'Liam', 'Olivia', 'Noah', 'Ava', 'James', 'Sophia', 'Lucas' ],
        'es' => [ 'Lucía', 'Hugo', 'Martina', 'Mateo', 'Sofía', 'Pablo', 'Valeria', 'Álvaro' ],
        'fr' => [ 'Camille', 'Louis', 'Léa', 'Gabriel', 'Chloé', 'Jules', 'Manon', 'Théo' ],
        'de' => [ 'Mia', 'Ben', 'Hannah', 'Paul', 'Lena', 'Jonas', 'Marie', 'Felix' ],
    ];

    /**
     * Shopper last names per locale.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const LAST_NAMES = [
        'en' => [ 'Smith', 'Johnson', 'Brown', 'Taylor', 'Miller', 'Wilson', 'Clark', 'Walker' ],
        'es' => [ 'García', 'Martínez', 'López', 'Sánchez', 'Pérez', 'Gómez', 'Fernández', 'Ruiz' ],
        'fr' => [ 'Martin', 'Bernard', 'Dubois', 'Moreau', 'Laurent', 'Lefèvre', 'Girard', 'Roux' ],
        'de' => [ 'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Wagner', 'Becker', 'Hoffmann' ],
    ];

    /**
     * Address pool per country: street names, cities, region codes, and a
     * postal-code format (`#` becomes a digit).
     *
     * @since 1.0.0
     *
     * @var array<string, array{streets: array<int, string>, cities: array<int, array{0: string, 1: string|null}>, postal: string, street_first: bool}>
     */
    public const ADDRESSES = [
        'US' => [
            'streets'      => [ 'Maple Street', 'Oak Avenue', 'Pine Road', 'Cedar Lane' ],
            'cities'       => [ [ 'Portland', 'OR' ], [ 'Austin', 'TX' ], [ 'Denver', 'CO' ], [ 'Chicago', 'IL' ] ],
            'postal'       => '#####',
            'street_first' => false,
        ],
        'GB' => [
            'streets'      => [ 'High Street', 'Church Road', 'Station Road', 'Mill Lane' ],
            'cities'       => [ [ 'London', null ], [ 'Manchester', null ], [ 'Bristol', null ], [ 'Leeds', null ] ],
            'postal'       => 'SW# #AB',
            'street_first' => false,
        ],
        'ES' => [
            'streets'      => [ 'Calle Mayor', 'Avenida de la Constitución', 'Calle del Sol', 'Paseo de Gracia' ],
            'cities'       => [ [ 'Madrid', 'M' ], [ 'Barcelona', 'B' ], [ 'Valencia', 'V' ], [ 'Sevilla', 'SE' ] ],
            'postal'       => '#####',
            'street_first' => true,
        ],
        'FR' => [
            'streets'      => [ 'rue de la Paix', 'avenue Victor Hugo', 'boulevard Voltaire', 'rue du Moulin' ],
            'cities'       => [ [ 'Paris', null ], [ 'Lyon', null ], [ 'Marseille', null ], [ 'Bordeaux', null ] ],
            'postal'       => '#####',
            'street_first' => false,
        ],
        'DE' => [
            'streets'      => [ 'Hauptstraße', 'Schulstraße', 'Gartenweg', 'Bahnhofstraße' ],
            'cities'       => [ [ 'Berlin', 'BE' ], [ 'München', 'BY' ], [ 'Hamburg', 'HH' ], [ 'Köln', 'NW' ] ],
            'postal'       => '#####',
            'street_first' => true,
        ],
    ];

    /**
     * Review copy per locale, keyed by sentiment (`positive` for 4–5
     * stars, `mixed` for 3, `negative` for 1–2). Each entry is
     * `[ title, body ]`.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, array<int, array{0: string, 1: string}>>>
     */
    public const REVIEWS = [
        'en' => [
            'positive' => [
                [ 'Love it', 'Exactly as described and it arrived quickly. Would buy again.' ],
                [ 'Great quality', 'Feels well made and looks even better in person.' ],
            ],
            'mixed'    => [ [ 'Decent', 'Good overall, but not quite what I expected.' ] ],
            'negative' => [ [ 'Disappointed', 'The quality did not match the price, sadly.' ] ],
        ],
        'es' => [
            'positive' => [
                [ 'Me encanta', 'Tal como se describe y llegó muy rápido. Volvería a comprarlo.' ],
                [ 'Gran calidad', 'Se nota bien hecho y en persona es aún más bonito.' ],
            ],
            'mixed'    => [ [ 'Correcto', 'Bien en general, pero no era exactamente lo que esperaba.' ] ],
            'negative' => [ [ 'Decepcionado', 'La calidad no está a la altura del precio.' ] ],
        ],
        'fr' => [
            'positive' => [
                [ 'Je l\'adore', 'Conforme à la description et livré rapidement. Je recommande.' ],
                [ 'Très bonne qualité', 'Bien fini, et encore plus beau en vrai.' ],
            ],
            'mixed'    => [ [ 'Correct', 'Bien dans l\'ensemble, mais pas tout à fait ce que j\'attendais.' ] ],
            'negative' => [ [ 'Déçu', 'La qualité n\'est pas à la hauteur du prix.' ] ],
        ],
        'de' => [
            'positive' => [
                [ 'Begeistert', 'Genau wie beschrieben und schnell geliefert. Gerne wieder.' ],
                [ 'Tolle Qualität', 'Sehr gut verarbeitet und in echt noch schöner.' ],
            ],
            'mixed'    => [ [ 'Ganz okay', 'Insgesamt gut, aber nicht ganz das, was ich erwartet hatte.' ] ],
            'negative' => [ [ 'Enttäuscht', 'Die Qualität entspricht leider nicht dem Preis.' ] ],
        ],
    ];

    /**
     * Order notes shoppers leave at checkout, per locale.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const CUSTOMER_NOTES = [
        'en' => [ 'Please leave the parcel with a neighbour.', 'This is a gift — no invoice in the box, please.' ],
        'es' => [ 'Por favor, dejen el paquete con un vecino.', 'Es un regalo, por favor no incluyan la factura.' ],
        'fr' => [ 'Merci de laisser le colis chez un voisin.', 'C\'est un cadeau, merci de ne pas joindre la facture.' ],
        'de' => [ 'Bitte das Paket beim Nachbarn abgeben.', 'Das ist ein Geschenk – bitte keine Rechnung beilegen.' ],
    ];

    /**
     * Tax rates for the `standard` tax class, per country, as
     * `[ rate_ubps, label ]`. Labels follow the locale-driven tax terms
     * from parent plan §16.5.
     *
     * @since 1.0.0
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const TAX_RATES = [
        'US' => [ 82_500_000, 'Sales Tax' ],
        'GB' => [ 200_000_000, 'VAT' ],
        'ES' => [ 210_000_000, 'IVA' ],
        'FR' => [ 200_000_000, 'TVA' ],
        'DE' => [ 190_000_000, 'USt.' ],
    ];
}
