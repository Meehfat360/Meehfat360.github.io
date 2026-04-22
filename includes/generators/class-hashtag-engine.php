<?php
namespace SSCA\Generators;

defined( 'ABSPATH' ) || exit;

/**
 * Hashtag Intelligence Engine
 *
 * Builds a tiered hashtag set (mega/mid/niche) per platform and category.
 * Tracks performance and avoids banned/shadowban-risk tags.
 */
class HashtagEngine {

    // Banned/high-risk Instagram hashtags (expand over time)
    const BANNED = [
        'followme', 'follow4follow', 'like4like', 'likeforlike', 'f4f',
        'l4l', 'spamforspam', 'gain', 'followback',
    ];

    /**
     * Build a hashtag string for a product on a given platform.
     */
    public function build( \WC_Product $product, string $platform, int $count = 25 ): string {
        if ( $platform !== 'instagram' ) return ''; // Only Instagram uses hashtags in posts

        $tags    = [];
        $cats    = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        $cat     = ! empty( $cats ) && ! is_wp_error( $cats ) ? strtolower( $cats[0] ) : 'shopping';
        $product_tag_terms = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );

        // 1. Pull performing tags from DB
        $db_tags = \SSCA\DB\Repository::get_hashtags( $platform, $cat, 10 );
        foreach ( $db_tags as $row ) {
            $tags[] = $row['hashtag'];
        }

        // 2. Add category-specific defaults
        $tags = array_merge( $tags, $this->get_category_tags( $cat ) );

        // 3. Add product-specific tags from WC product tags
        if ( ! is_wp_error( $product_tag_terms ) ) {
            foreach ( $product_tag_terms as $term ) {
                $tags[] = '#' . preg_replace( '/\s+/', '', strtolower( $term ) );
            }
        }

        // 4. Add general shopping/deal tags
        $tags = array_merge( $tags, $this->get_general_tags() );

        // 5. Filter, dedupe, check banned
        $tags = array_unique(
            array_filter( $tags, fn( $t ) => $this->is_safe( $t ) )
        );

        // 6. Ensure tier balance
        $tags = $this->balance_tiers( $tags, $platform );

        // 7. Trim to count
        $tags = array_slice( $tags, 0, $count );

        // 8. Record usage
        foreach ( $tags as $tag ) {
            \SSCA\DB\Repository::record_hashtag_use( $tag, $platform );
        }

        return implode( ' ', $tags );
    }

    private function is_safe( string $tag ): bool {
        $clean = ltrim( strtolower( $tag ), '#' );
        return ! in_array( $clean, self::BANNED, true ) && strlen( $clean ) >= 2 && strlen( $clean ) <= 50;
    }

    private function balance_tiers( array $tags, string $platform ): array {
        // Ensure mix: mega(1M+), mid(100K-1M), niche(<100K)
        // In practice: use curated lists per tier
        $mega  = array_intersect( $tags, $this->get_mega_tags() );
        $mid   = array_intersect( $tags, $this->get_mid_tags() );
        $niche = array_diff( $tags, $mega, $mid );

        return array_merge(
            array_slice( array_values( $mega ),  0, 5 ),
            array_slice( array_values( $mid ),   0, 10 ),
            array_slice( array_values( $niche ), 0, 10 )
        );
    }

    private function get_mega_tags(): array {
        return [
            '#shopping', '#sale', '#fashion', '#style', '#beauty', '#lifestyle',
            '#love', '#instagood', '#photooftheday', '#picoftheday', '#ootd',
            '#onlineshopping', '#deals', '#shop', '#instashop',
        ];
    }

    private function get_mid_tags(): array {
        return [
            '#shopnow', '#salealert', '#dealoftheday', '#limitedtime', '#musthave',
            '#newproduct', '#shopsmall', '#onlinestore', '#discountdeals',
            '#productreview', '#shoplocal', '#ecommerce', '#freeshipping',
            '#giftsforhim', '#giftsforher', '#treatyourself', '#wishlist',
        ];
    }

    private function get_general_tags(): array {
        return [
            '#deals', '#musthave', '#shopnow', '#sale', '#onlineshopping',
            '#newproduct', '#limitedtime', '#treatyourself',
        ];
    }

    private function get_category_tags( string $category ): array {
        $map = [
            'clothing'    => [ '#fashion', '#style', '#outfit', '#ootd', '#womensfashion', '#mensfashion', '#streetstyle', '#clothing' ],
            'shoes'       => [ '#shoes', '#footwear', '#sneakers', '#heels', '#kicks', '#shoeaddict', '#shoelover' ],
            'beauty'      => [ '#beauty', '#skincare', '#makeup', '#selfcare', '#cosmetics', '#beautyproducts', '#glowup' ],
            'electronics' => [ '#tech', '#gadgets', '#technology', '#electronics', '#smartphone', '#techreview', '#innovation' ],
            'home'        => [ '#homedecor', '#interiordesign', '#homedesign', '#livingroom', '#homestyle', '#decor' ],
            'food'        => [ '#food', '#foodie', '#yummy', '#foodphotography', '#cooking', '#recipe', '#delicious' ],
            'fitness'     => [ '#fitness', '#workout', '#gym', '#health', '#wellness', '#fitlife', '#exercise', '#active' ],
            'toys'        => [ '#toys', '#kids', '#children', '#play', '#toysofinstagram', '#toddler', '#parenting' ],
            'books'       => [ '#books', '#reading', '#bookstagram', '#bookworm', '#booklover', '#literature', '#reader' ],
            'jewelry'     => [ '#jewelry', '#accessories', '#necklace', '#rings', '#handmade', '#jewellery', '#bling' ],
        ];

        foreach ( $map as $key => $tags ) {
            if ( str_contains( $category, $key ) ) return $tags;
        }

        return [ '#shopping', '#onlinestore', '#buynow', '#greatdeal', '#shopnow' ];
    }
}
