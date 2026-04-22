<?php
namespace SSCA\Engines;

defined( 'ABSPATH' ) || exit;

/**
 * Seasonal Campaign Engine
 *
 * Automatically detects which marketing theme is active based on date.
 * Store owners can override or add custom seasons in settings.
 */
class SeasonalEngine {

    /**
     * Returns the active theme slug for today.
     */
    public function get_current_theme(): string {
        $custom = $this->get_custom_override();
        if ( $custom ) return $custom;

        $today = (int) current_time( 'md' ); // e.g. 1225 for Dec 25
        $month = (int) current_time( 'n' );
        $day   = (int) current_time( 'j' );

        return $this->match_theme( $month, $day );
    }

    private function match_theme( int $month, int $day ): string {
        $md = (int) sprintf( '%d%02d', $month, $day );

        $themes = [
            // January
            [ 'from' => 101,  'to' => 131,  'theme' => 'new_year_sale'     ],
            // February
            [ 'from' => 201,  'to' => 214,  'theme' => 'valentines_day'    ],
            [ 'from' => 215,  'to' => 228,  'theme' => 'winter_clearance'  ],
            // March
            [ 'from' => 301,  'to' => 317,  'theme' => 'spring_preview'    ],
            [ 'from' => 318,  'to' => 331,  'theme' => 'spring_sale'       ],
            // April
            [ 'from' => 401,  'to' => 415,  'theme' => 'easter'            ],
            [ 'from' => 416,  'to' => 430,  'theme' => 'spring_sale'       ],
            // May
            [ 'from' => 501,  'to' => 510,  'theme' => 'mothers_day'       ],
            [ 'from' => 511,  'to' => 531,  'theme' => 'summer_preview'    ],
            // June
            [ 'from' => 601,  'to' => 621,  'theme' => 'fathers_day'       ],
            [ 'from' => 622,  'to' => 630,  'theme' => 'summer_sale'       ],
            // July
            [ 'from' => 701,  'to' => 731,  'theme' => 'summer_sale'       ],
            // August
            [ 'from' => 801,  'to' => 815,  'theme' => 'back_to_school'    ],
            [ 'from' => 816,  'to' => 831,  'theme' => 'late_summer'       ],
            // September
            [ 'from' => 901,  'to' => 930,  'theme' => 'fall_preview'      ],
            // October
            [ 'from' => 1001, 'to' => 1031, 'theme' => 'halloween'         ],
            // November
            [ 'from' => 1101, 'to' => 1121, 'theme' => 'pre_black_friday'  ],
            [ 'from' => 1122, 'to' => 1130, 'theme' => 'black_friday'      ],
            // December
            [ 'from' => 1201, 'to' => 1220, 'theme' => 'christmas'         ],
            [ 'from' => 1221, 'to' => 1231, 'theme' => 'year_end_sale'     ],
        ];

        foreach ( $themes as $t ) {
            if ( $md >= $t['from'] && $md <= $t['to'] ) return $t['theme'];
        }

        return 'default';
    }

    /**
     * Get theme metadata: display name, colors, emoji, keywords.
     */
    public function get_theme_meta( string $theme ): array {
        $themes = [
            'new_year_sale'    => [ 'label' => 'New Year Sale',     'colors' => [ '#FFD700', '#1a1a2e' ], 'emoji' => '🎉', 'cta' => 'Start the Year Right!'      ],
            'valentines_day'   => [ 'label' => "Valentine's Day",   'colors' => [ '#FF6B9D', '#FFF0F5' ], 'emoji' => '❤️', 'cta' => 'Share the Love!'             ],
            'winter_clearance' => [ 'label' => 'Winter Clearance',  'colors' => [ '#4A90D9', '#E8F4FD' ], 'emoji' => '❄️', 'cta' => 'Clearance Prices!'           ],
            'spring_preview'   => [ 'label' => 'Spring Preview',    'colors' => [ '#7BC67E', '#FFF8DC' ], 'emoji' => '🌸', 'cta' => 'Spring is Coming!'           ],
            'spring_sale'      => [ 'label' => 'Spring Sale',       'colors' => [ '#7BC67E', '#FFFACD' ], 'emoji' => '🌷', 'cta' => 'Fresh Spring Deals!'         ],
            'easter'           => [ 'label' => 'Easter',            'colors' => [ '#FFB347', '#E6E6FA' ], 'emoji' => '🐣', 'cta' => 'Easter Savings Inside!'      ],
            'mothers_day'      => [ 'label' => "Mother's Day",      'colors' => [ '#FF9999', '#FFF5F5' ], 'emoji' => '💐', 'cta' => 'Make Mom Feel Special!'       ],
            'summer_preview'   => [ 'label' => 'Summer Preview',    'colors' => [ '#FFB347', '#FFFDE7' ], 'emoji' => '☀️', 'cta' => 'Summer is Almost Here!'      ],
            'fathers_day'      => [ 'label' => "Father's Day",      'colors' => [ '#4682B4', '#F0F8FF' ], 'emoji' => '👔', 'cta' => 'Gifts Dad Will Love!'         ],
            'summer_sale'      => [ 'label' => 'Summer Sale',       'colors' => [ '#FF6347', '#FFF5E6' ], 'emoji' => '🌞', 'cta' => 'Hot Summer Deals!'           ],
            'back_to_school'   => [ 'label' => 'Back to School',    'colors' => [ '#4169E1', '#F0F8FF' ], 'emoji' => '🎒', 'cta' => 'School-Ready Savings!'        ],
            'late_summer'      => [ 'label' => 'Late Summer',       'colors' => [ '#FF7F50', '#FFF8F0' ], 'emoji' => '🏖️', 'cta' => 'Last Days of Summer!'        ],
            'fall_preview'     => [ 'label' => 'Fall Preview',      'colors' => [ '#D2691E', '#FFF8DC' ], 'emoji' => '🍂', 'cta' => 'Fall Into Great Deals!'       ],
            'halloween'        => [ 'label' => 'Halloween',         'colors' => [ '#FF6600', '#1C1C1C' ], 'emoji' => '🎃', 'cta' => 'Spooky Good Savings!'         ],
            'pre_black_friday' => [ 'label' => 'Pre-Black Friday',  'colors' => [ '#2C2C2C', '#FFD700' ], 'emoji' => '⏰', 'cta' => 'Black Friday Starts Early!'   ],
            'black_friday'     => [ 'label' => 'Black Friday',      'colors' => [ '#000000', '#FFD700' ], 'emoji' => '🖤', 'cta' => 'Best Deals of the Year!'      ],
            'christmas'        => [ 'label' => 'Christmas',         'colors' => [ '#CC0000', '#006400' ], 'emoji' => '🎄', 'cta' => 'Christmas Deals Are Here!'    ],
            'year_end_sale'    => [ 'label' => 'Year End Sale',     'colors' => [ '#4B0082', '#FFD700' ], 'emoji' => '🥂', 'cta' => 'End of Year Mega Sale!'       ],
            'default'          => [ 'label' => 'General',           'colors' => [ '#1E40AF', '#F8FAFC' ], 'emoji' => '🛍️', 'cta' => 'Shop Today!'                 ],
        ];

        return $themes[ $theme ] ?? $themes['default'];
    }

    /**
     * Keywords used to match products to a seasonal theme.
     */
    public function get_product_keyword_map(): array {
        return [
            'valentines_day'   => [ 'love', 'heart', 'gift', 'romantic', 'couple', 'red', 'rose', 'chocolate' ],
            'mothers_day'      => [ 'mom', 'mother', 'floral', 'spa', 'self-care', 'gift', 'perfume', 'candle' ],
            'fathers_day'      => [ 'dad', 'father', 'man', 'beard', 'tool', 'sport', 'outdoor', 'grill' ],
            'back_to_school'   => [ 'school', 'student', 'bag', 'notebook', 'pen', 'desk', 'study', 'learn' ],
            'halloween'        => [ 'spooky', 'costume', 'candy', 'decoration', 'ghost', 'pumpkin', 'horror' ],
            'black_friday'     => [ 'deal', 'sale', 'discount', 'offer', 'clearance', 'save' ],
            'christmas'        => [ 'christmas', 'holiday', 'gift', 'winter', 'festive', 'decoration', 'santa' ],
            'summer_sale'      => [ 'beach', 'summer', 'sun', 'swim', 'outdoor', 'travel', 'sport', 'cool' ],
            'spring_sale'      => [ 'spring', 'fresh', 'garden', 'floral', 'outdoor', 'clean', 'renewal' ],
            'easter'           => [ 'easter', 'spring', 'egg', 'bunny', 'pastel', 'garden', 'candy' ],
        ];
    }

    private function get_custom_override(): string {
        $override = get_option( 'ssca_season_override', '' );
        if ( ! empty( $override ) ) {
            $expiry = (int) get_option( 'ssca_season_override_expiry', 0 );
            if ( $expiry === 0 || $expiry > time() ) return $override;
            delete_option( 'ssca_season_override' );
            delete_option( 'ssca_season_override_expiry' );
        }
        return '';
    }

    public function set_override( string $theme, int $days = 7 ): void {
        update_option( 'ssca_season_override', $theme );
        update_option( 'ssca_season_override_expiry', time() + ( $days * DAY_IN_SECONDS ) );
    }
}
