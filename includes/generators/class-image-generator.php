<?php
namespace SSCA\Generators;

defined( 'ABSPATH' ) || exit;

/**
 * Image Generator
 *
 * Creates social media creatives using PHP GD with branded templates.
 * Generates 4 formats per product per variant:
 *   - square       (1080×1080) — Instagram / Facebook
 *   - landscape    (1200×630)  — Facebook link post
 *   - story        (1080×1920) — Instagram/Facebook Story
 *   - pinterest    (1000×1500) — Pinterest vertical pin
 */
class ImageGenerator {

    private array  $brand;
    private string $upload_dir;
    private string $upload_url;

    // Format definitions [width, height]
    const FORMATS = [
        'square'    => [ 1080, 1080 ],
        'landscape' => [ 1200, 630  ],
        'story'     => [ 1080, 1920 ],
        'pinterest' => [ 1000, 1500 ],
    ];

    // Platform → preferred format
    const PLATFORM_FORMAT = [
        'instagram' => 'square',
        'facebook'  => 'landscape',
        'story'     => 'story',
        'pinterest' => 'pinterest',
    ];

    public function __construct() {
        $brand_profile  = new \SSCA\Utils\BrandProfile();
        $this->brand    = $brand_profile->get();

        $upload         = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/ssca-creatives/';
        $this->upload_url = $upload['baseurl'] . '/ssca-creatives/';
        wp_mkdir_p( $this->upload_dir );
    }

    /**
     * Generate creatives for all platforms for a product.
     *
     * @return array<string, string>  platform => image_path
     */
    public function generate( \WC_Product $product, string $variant = 'A', string $season = 'default' ): array {
        $season_meta = ( new \SSCA\Engines\SeasonalEngine() )->get_theme_meta( $season );
        $result      = [];

        foreach ( self::PLATFORM_FORMAT as $platform => $format ) {
            $path = $this->render( $product, $format, $variant, $season_meta );
            if ( $path ) $result[ $platform ] = $path;
        }

        return $result;
    }

    /**
     * Deal alert creative — urgent red overlay style.
     */
    public function generate_deal_alert( \WC_Product $product ): string {
        $deal_meta = [
            'label'  => '🔥 Deal Alert',
            'colors' => [ '#FF0000', '#1A0000' ],
            'emoji'  => '🔥',
            'cta'    => 'Limited Time Only!',
        ];
        return $this->render( $product, 'square', 'DEAL', $deal_meta ) ?? '';
    }

    // ── Core Render Engine ────────────────────────────────────────────────────

    private function render( \WC_Product $product, string $format, string $variant, array $season_meta ): ?string {
        if ( ! extension_loaded( 'gd' ) ) {
            \SSCA\Utils\Logger::warning( 'GD extension not available. Skipping image generation.' );
            return null;
        }

        [ $width, $height ] = self::FORMATS[ $format ] ?? [ 1080, 1080 ];

        $filename = sprintf(
            '%s-%s-%s-%s-%s.jpg',
            $product->get_id(),
            $format,
            $variant,
            sanitize_title( $season_meta['label'] ?? 'default' ),
            date( 'Ymd' )
        );
        $filepath = $this->upload_dir . $filename;

        // Use cached if exists and fresh (same day)
        if ( file_exists( $filepath ) && filemtime( $filepath ) > strtotime( 'today' ) ) {
            return $filepath;
        }

        // 1. Create canvas
        $canvas = imagecreatetruecolor( $width, $height );
        imagealphablending( $canvas, true );
        imagesavealpha( $canvas, true );

        // 2. Background
        $this->draw_background( $canvas, $width, $height, $season_meta );

        // 3. Product image
        $this->draw_product_image( $canvas, $product, $width, $height, $format );

        // 4. Brand overlay
        $this->draw_brand_bar( $canvas, $width, $height, $format );

        // 5. Overlays: price, discount, title
        $this->draw_product_info( $canvas, $product, $width, $height, $format, $variant );

        // 6. Season badge / CTA
        $this->draw_season_badge( $canvas, $season_meta, $width, $height, $format );

        // 7. Watermark / logo
        $this->draw_logo( $canvas, $width, $height );

        // Save
        imagejpeg( $canvas, $filepath, 92 );
        imagedestroy( $canvas );

        return $filepath;
    }

    // ── Drawing Helpers ───────────────────────────────────────────────────────

    private function draw_background( $canvas, int $w, int $h, array $season_meta ): void {
        $c1 = $this->hex_to_rgb( $season_meta['colors'][0] ?? $this->brand['primary_color'] ?? '#1E40AF' );
        $c2 = $this->hex_to_rgb( $season_meta['colors'][1] ?? $this->brand['secondary_color'] ?? '#F8FAFC' );

        // Gradient background
        for ( $y = 0; $y < $h; $y++ ) {
            $ratio = $y / $h;
            $r = (int) ( $c1[0] + ( $c2[0] - $c1[0] ) * $ratio );
            $g = (int) ( $c1[1] + ( $c2[1] - $c1[1] ) * $ratio );
            $b = (int) ( $c1[2] + ( $c2[2] - $c1[2] ) * $ratio );
            $color = imagecolorallocate( $canvas, $r, $g, $b );
            imageline( $canvas, 0, $y, $w, $y, $color );
        }
    }

    private function draw_product_image( $canvas, \WC_Product $product, int $w, int $h, string $format ): void {
        $image_id  = $product->get_image_id();
        if ( ! $image_id ) return;

        $image_path = get_attached_file( $image_id );
        if ( ! $image_path || ! file_exists( $image_path ) ) return;

        $ext = strtolower( pathinfo( $image_path, PATHINFO_EXTENSION ) );
        $src = match( $ext ) {
            'jpg', 'jpeg' => @imagecreatefromjpeg( $image_path ),
            'png'         => @imagecreatefrompng( $image_path ),
            'webp'        => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $image_path ) : false,
            default       => false,
        };

        if ( ! $src ) return;

        $src_w = imagesx( $src );
        $src_h = imagesy( $src );

        // Calculate destination area (centered, with padding)
        $pad   = (int) ( $w * 0.1 );
        $dst_w = (int) ( $w * 0.8 );
        $dst_h = $format === 'square' ? $dst_w : (int) ( $h * 0.6 );
        $dst_x = (int) ( ( $w - $dst_w ) / 2 );
        $dst_y = $format === 'square'
            ? (int) ( $h * 0.05 )
            : (int) ( $h * 0.1 );

        // Maintain aspect ratio
        $ratio   = min( $dst_w / $src_w, $dst_h / $src_h );
        $new_w   = (int) ( $src_w * $ratio );
        $new_h   = (int) ( $src_h * $ratio );
        $offset_x = $dst_x + (int) ( ( $dst_w - $new_w ) / 2 );
        $offset_y = $dst_y + (int) ( ( $dst_h - $new_h ) / 2 );

        imagecopyresampled( $canvas, $src, $offset_x, $offset_y, 0, 0, $new_w, $new_h, $src_w, $src_h );
        imagedestroy( $src );
    }

    private function draw_brand_bar( $canvas, int $w, int $h, string $format ): void {
        // Bottom bar with brand color
        $bar_h = (int) ( $h * 0.12 );
        $y     = $h - $bar_h;
        $color = $this->hex_to_rgb( $this->brand['primary_color'] ?? '#1E40AF' );
        $c     = imagecolorallocatealpha( $canvas, $color[0], $color[1], $color[2], 20 );
        imagefilledrectangle( $canvas, 0, $y, $w, $h, $c );
    }

    private function draw_product_info( $canvas, \WC_Product $product, int $w, int $h, string $format, string $variant ): void {
        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        $dark  = imagecolorallocate( $canvas, 30, 30, 30 );
        $red   = imagecolorallocate( $canvas, 220, 50, 50 );

        $name   = mb_substr( $product->get_name(), 0, 40 );
        $price  = wc_price( $product->get_price() );
        $regular= $product->get_regular_price();
        $sale   = $product->get_sale_price();

        $font    = 5; // GD built-in font (5 = largest)
        $font_sm = 3;
        $font_xs = 2;

        // Product name
        $y_name = (int) ( $h * 0.72 );
        $this->draw_text_with_shadow( $canvas, $font, 20, $y_name, $name, $white, $dark );

        // Price
        $y_price = $y_name + 30;
        $clean_price = html_entity_decode( strip_tags( $price ) );
        $this->draw_text_with_shadow( $canvas, $font, 20, $y_price, $clean_price, $white, $dark );

        // Discount badge if on sale
        if ( $product->is_on_sale() && $regular > 0 && $sale > 0 ) {
            $pct   = round( ( ( $regular - $sale ) / $regular ) * 100 );
            $badge = "-{$pct}%";
            $bx    = $w - 110;
            $by    = (int) ( $h * 0.05 );
            imagefilledellipse( $canvas, $bx + 45, $by + 45, 90, 90, $red );
            imagestring( $canvas, $font, $bx + 12, $by + 35, $badge, $white );
        }

        // Variant marker (subtle)
        if ( in_array( $variant, [ 'A', 'B' ], true ) ) {
            $vc = imagecolorallocatealpha( $canvas, 255, 255, 255, 100 );
            imagestring( $canvas, $font_xs, 5, 5, "v{$variant}", $vc );
        }
    }

    private function draw_season_badge( $canvas, array $season_meta, int $w, int $h, string $format ): void {
        if ( empty( $season_meta['cta'] ) || $season_meta['label'] === 'General' ) return;

        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        $font  = 3;
        $cta   = $season_meta['emoji'] . ' ' . $season_meta['cta'];
        $x     = 20;
        $y     = (int) ( $h * 0.88 );
        imagestring( $canvas, $font, $x, $y, $cta, $white );
    }

    private function draw_logo( $canvas, int $w, int $h ): void {
        $logo_path = $this->brand['logo_path'] ?? '';
        if ( ! $logo_path || ! file_exists( $logo_path ) ) {
            // Text watermark fallback
            $color = imagecolorallocatealpha( $canvas, 255, 255, 255, 80 );
            $name  = $this->brand['store_name'] ?? get_bloginfo( 'name' );
            imagestring( $canvas, 2, $w - ( strlen( $name ) * 7 ) - 10, 10, $name, $color );
            return;
        }

        $ext  = strtolower( pathinfo( $logo_path, PATHINFO_EXTENSION ) );
        $logo = match( $ext ) {
            'png'  => @imagecreatefrompng( $logo_path ),
            'jpg', 'jpeg' => @imagecreatefromjpeg( $logo_path ),
            default => false,
        };

        if ( ! $logo ) return;

        $lw = min( (int) ( $w * 0.15 ), 120 );
        $lh = (int) ( imagesy( $logo ) * ( $lw / imagesx( $logo ) ) );
        $lx = $w - $lw - 15;
        $ly = 10;

        imagecopyresampled( $canvas, $logo, $lx, $ly, 0, 0, $lw, $lh, imagesx( $logo ), imagesy( $logo ) );
        imagedestroy( $logo );
    }

    private function draw_text_with_shadow( $canvas, int $font, int $x, int $y, string $text, $color, $shadow_color ): void {
        imagestring( $canvas, $font, $x + 1, $y + 1, $text, $shadow_color );
        imagestring( $canvas, $font, $x,     $y,     $text, $color );
    }

    private function hex_to_rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    /**
     * Get the public URL for a generated image path.
     */
    public function path_to_url( string $path ): string {
        return str_replace( $this->upload_dir, $this->upload_url, $path );
    }
}
