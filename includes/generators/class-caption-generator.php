<?php
namespace SSCA\Generators;

defined( 'ABSPATH' ) || exit;

/**
 * AI Caption Generator (OpenAI GPT-4o)
 *
 * Generates platform-optimised captions with brand voice enforcement.
 * Supports: Facebook, Instagram, Pinterest
 * Variants: A (offer-focused), B (benefit-focused)
 * Special: Deal Alert, Seasonal
 */
class CaptionGenerator {

    private string $api_key;
    private string $model = 'gpt-4o';

    public function __construct() {
        $this->api_key = get_option( 'ssca_openai_key', '' );
    }

    /**
     * Generate captions for all platforms.
     *
     * @return array<string, string>  platform => caption
     */
    public function generate(
        \WC_Product $product,
        string $variant,
        array $platforms,
        \SSCA\Utils\BrandProfile $brand_profile,
        string $season = 'default'
    ): array {
        if ( empty( $this->api_key ) ) {
            return $this->fallback_captions( $product, $platforms );
        }

        $product_data  = $this->extract_product_data( $product );
        $brand         = $brand_profile->get();
        $season_meta   = ( new \SSCA\Engines\SeasonalEngine() )->get_theme_meta( $season );
        $review_quote  = $this->get_review_quote( $product );
        $results       = [];

        foreach ( $platforms as $platform ) {
            $prompt   = $this->build_prompt( $product_data, $brand, $season_meta, $variant, $platform, $review_quote );
            $response = $this->call_openai( $prompt );
            $results[ $platform ] = $response ?? $this->fallback_caption( $product, $platform );
        }

        return $results;
    }

    /**
     * Deal alert caption — urgent, time-sensitive tone.
     */
    public function generate_deal_alert( \WC_Product $product, string $platform ): string {
        if ( empty( $this->api_key ) ) {
            return $this->fallback_deal_alert( $product, $platform );
        }

        $data    = $this->extract_product_data( $product );
        $prompt  = $this->build_deal_alert_prompt( $data, $platform );
        return $this->call_openai( $prompt ) ?? $this->fallback_deal_alert( $product, $platform );
    }

    // ── Prompt Builders ───────────────────────────────────────────────────────

    private function build_prompt( array $product, array $brand, array $season, string $variant, string $platform, ?string $review_quote ): string {
        $variant_instruction = $variant === 'A'
            ? 'Focus on the OFFER/DEAL angle — price savings, discount, value for money.'
            : 'Focus on the BENEFIT/TRANSFORMATION angle — how this product improves the customer\'s life.';

        $platform_spec = $this->platform_specification( $platform );
        $tone          = $brand['tone'] ?? 'friendly and enthusiastic';
        $forbidden     = ! empty( $brand['forbidden_words'] ) ? 'Never use: ' . implode( ', ', $brand['forbidden_words'] ) . '.' : '';
        $tagline       = ! empty( $brand['tagline'] ) ? "End every post with the brand tagline: \"{$brand['tagline']}\"" : '';
        $review_block  = $review_quote ? "Use this real customer quote naturally in the copy: \"{$review_quote}\"" : '';
        $ftc           = $brand['add_ftc_disclosure'] ?? false ? 'Add #ad at the end if it appears promotional.' : '';

        return <<<PROMPT
You are a professional social media copywriter for {$brand['store_name']}.

PRODUCT INFORMATION:
- Name: {$product['name']}
- Price: {$product['price']}
- Regular Price: {$product['regular_price']}
- Discount: {$product['discount_pct']}% off
- Description: {$product['description']}
- Category: {$product['category']}
- Product URL: {$product['url']}
- In Stock: {$product['stock']} units

CAMPAIGN:
- Season/Theme: {$season['label']} {$season['emoji']}
- CTA theme: {$season['cta']}

WRITING INSTRUCTIONS:
- Platform: {$platform_spec}
- Tone: {$tone}
- Variant {$variant}: {$variant_instruction}
- {$forbidden}
- {$tagline}
- {$review_block}
- {$ftc}
- Always include the product URL as a call-to-action.
- Do NOT include placeholder brackets like [your name] or [link].
- Write the final, publish-ready caption only. No explanations.

OUTPUT FORMAT:
Return ONLY the caption text. For Instagram include hashtags at the end. For Pinterest include keyword-rich description.
PROMPT;
    }

    private function build_deal_alert_prompt( array $product, string $platform ): string {
        $platform_spec = $this->platform_specification( $platform );
        $store_name    = get_bloginfo( 'name' );

        return <<<PROMPT
You are a social media copywriter creating an URGENT DEAL ALERT post.

PRODUCT:
- Name: {$product['name']}
- Sale Price: {$product['price']}
- Regular Price: {$product['regular_price']}
- Discount: {$product['discount_pct']}% OFF
- URL: {$product['url']}
- Store: {$store_name}

INSTRUCTIONS:
- Platform: {$platform_spec}
- Tone: URGENT, excited, time-sensitive
- Use emojis to convey urgency (🔥⚡⏰)
- Emphasize the price drop and limited time
- Include a clear call-to-action with the URL
- Short and punchy — this should feel like a flash sale alert
- Return ONLY the caption text. No explanations.
PROMPT;
    }

    private function platform_specification( string $platform ): string {
        return match( $platform ) {
            'facebook' => 'Facebook (write 2-3 paragraphs, conversational tone, include a question to drive comments, end with the URL)',
            'instagram' => 'Instagram (write 1 punchy opening line, 3-5 short sentences, then 20-25 relevant hashtags on a new line — mix mega/mid/niche)',
            'pinterest' => 'Pinterest (write an SEO-optimised description 100-200 words, naturally weave in searchable keywords, describe the product and its benefits, include the URL)',
            default     => 'Social media (engaging, clear CTA, include URL)',
        };
    }

    // ── OpenAI API ────────────────────────────────────────────────────────────

    private function call_openai( string $prompt ): ?string {
        $start    = microtime( true );
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => $this->model,
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'You are an expert social media copywriter. Return only the final caption, no meta-commentary.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'max_tokens'  => 600,
                'temperature' => 0.75,
            ] ),
        ] );

        $duration = (int) ( ( microtime( true ) - $start ) * 1000 );
        $success  = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;

        \SSCA\DB\Repository::insert_api_log( [
            'platform'      => 'openai',
            'endpoint'      => '/v1/chat/completions',
            'method'        => 'POST',
            'response_code' => wp_remote_retrieve_response_code( $response ),
            'duration_ms'   => $duration,
            'success'       => $success ? 1 : 0,
            'error_msg'     => is_wp_error( $response ) ? $response->get_error_message() : null,
        ] );

        if ( ! $success ) {
            \SSCA\Utils\Logger::error( 'OpenAI API failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return trim( $body['choices'][0]['message']['content'] ?? '' ) ?: null;
    }

    // ── Product Data Extraction ───────────────────────────────────────────────

    private function extract_product_data( \WC_Product $product ): array {
        $regular   = (float) $product->get_regular_price();
        $sale      = (float) $product->get_sale_price();
        $price     = (float) $product->get_price();
        $discount  = ( $regular > 0 && $sale > 0 ) ? round( ( ( $regular - $sale ) / $regular ) * 100 ) : 0;

        $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );

        return [
            'name'          => $product->get_name(),
            'description'   => wp_strip_all_tags( $product->get_short_description() ?: wp_trim_words( $product->get_description(), 30 ) ),
            'price'         => html_entity_decode( strip_tags( wc_price( $price ) ) ),
            'regular_price' => $regular > 0 ? html_entity_decode( strip_tags( wc_price( $regular ) ) ) : '',
            'discount_pct'  => $discount,
            'category'      => ! empty( $cats ) && ! is_wp_error( $cats ) ? implode( ', ', $cats ) : 'General',
            'url'           => get_permalink( $product->get_id() ),
            'stock'         => $product->get_stock_quantity() ?? 'In Stock',
        ];
    }

    /**
     * Mine a positive review quote to inject into copy.
     */
    private function get_review_quote( \WC_Product $product ): ?string {
        $reviews = get_comments( [
            'post_id'      => $product->get_id(),
            'status'       => 'approve',
            'meta_key'     => 'rating',
            'meta_value'   => '5',
            'number'       => 5,
            'orderby'      => 'rand',
        ] );

        foreach ( $reviews as $review ) {
            $text = trim( strip_tags( $review->comment_content ) );
            // Pick quotes 20–100 chars long
            if ( mb_strlen( $text ) >= 20 && mb_strlen( $text ) <= 100 ) {
                return $text;
            }
        }
        return null;
    }

    // ── Fallbacks (no API key) ────────────────────────────────────────────────

    private function fallback_captions( \WC_Product $product, array $platforms ): array {
        $result = [];
        foreach ( $platforms as $p ) {
            $result[ $p ] = $this->fallback_caption( $product, $p );
        }
        return $result;
    }

    private function fallback_caption( \WC_Product $product, string $platform ): string {
        $name  = $product->get_name();
        $price = html_entity_decode( strip_tags( wc_price( $product->get_price() ) ) );
        $url   = get_permalink( $product->get_id() );
        $sale  = $product->is_on_sale() ? ' Now on SALE! 🔥' : '';

        return match( $platform ) {
            'instagram' => "✨ {$name} — only {$price}!{$sale}\n\nShop now 👇\n{$url}\n\n#deals #shopping #sale #musthave #onlineshopping",
            'pinterest' => "{$name} — {$price}.{$sale} Discover amazing deals and shop online. Click to explore this product and more great finds. {$url}",
            default     => "🛍️ Check out {$name} for just {$price}!{$sale}\n\nShop now: {$url}",
        };
    }

    private function fallback_deal_alert( \WC_Product $product, string $platform ): string {
        $name    = $product->get_name();
        $price   = html_entity_decode( strip_tags( wc_price( $product->get_price() ) ) );
        $regular = html_entity_decode( strip_tags( wc_price( (float) $product->get_regular_price() ) ) );
        $url     = get_permalink( $product->get_id() );

        return "🔥 DEAL ALERT! {$name} dropped from {$regular} to {$price}! ⚡\n\nLimited time only — grab yours now:\n{$url}";
    }
}
