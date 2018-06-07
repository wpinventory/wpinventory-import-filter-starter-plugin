<?php
/**
 * Plugin Name:    WP Inventory Custom Filters for Importing Items
 * Description:    Custom Add-On to the WP Inventory plugin, filtering imported items based on dictionaries of words that should be replaced.  This plugin is INTENDED to be modified to set up the dictionaries, modify the "normalized" date, etc. <strong>*** FOR INSTRUCTIONS ** please view the plugin file - instructions are near the top of the file</strong>
 * Version:        0.0.2
 * Author:        WP Inventory Manager
 * Author URI:
 * http://www.wpinventory.com/
 */


/**
 *
 * === INSTRUCTIONS ===
 *
 * ** PLEASE READ THIS INTRO.... **
 *
 * This plugin is intended to be modified by you for your specific needs.
 * Modifications should be done by someone who is somewhat familiar with PHP.
 * This plugin has been carefully and thoroughly documented, so anyone with basic PHP knowledge should be able to
 * understand and modify what's needed below.
 *
 * ** MODIFYING THE PLUGIN'S FUNCTIONS ***
 *
 * There are four general areas you may wish to modify:
 *
 * 1. To update the dictionary for "Common" item importing (if you do NOT have Advanced Inventory Manager installed),
 * see the notes and instructions below, by searching for "Common Dictionary Instructions"
 *
 * 2. To update the dictionary for "Advanced" item importing (if you DO have Advanced Inventory Manager installed), see
 * the notes and instructions below, by searching for "Advanced Dictionary Instructions"
 *
 * 3. To "normalize" date values that are imported, from a variety of formats to a "standard" format, see the notes and
 * instructions below, by searching for "Date Normalizing Instructions"
 *
 * 4. To control which users are able to check the box for "Create Categories if don't exist", see the notes and
 * instructions below, by searching for "Control Categories Instructions" NOTE that presently, this is coded to handle
 * this based on user roles, but could be changed to be based on specific user ID's, or other criteria.
 *
 * 5. To control which users are able to check the box for "Create Options if don't exist" (which only exists if you
 * are using Advanced Inventory Manager) see the notes and instructions below by searching for "Control AIM Options
 * Instructions" NOTE that presently, this is coded to handle this based on user roles, but could be changed based on
 * specific user ID's, or other criteria.
 *
 */

/**
 * Class FilterDictionary
 *
 * Dictionaries for mapping imported values to new values.
 * Can be used to prevent common misspellings, abbreviations,
 * etc. from creating too many "options" for a given field's values.
 */
class WPIMFilterDictionary {
	/**
	 * === Common Dictionary Instructions ===
	 *
	 * Dictionary for the "common" (non-type-specific) words that should be remapped.
	 *
	 * NOTE that the "wrong term" or "lookup term" should always be all lowercase.
	 * The correct term should be with the proper uppercase desired.
	 *
	 * Example structure is [
	 * "inventory_fob" => [
	 *      "wrong term"    => "Correct Term",
	 *      "another wrong" => "Correct Term"
	 *      ],
	 * "inventory_serial" => [
	 *      "vacc"      => "Vaccine",
	 *      "vacine"    => "Vaccine",
	 *      ]
	 *
	 * @var array
	 */
	public static $common = [
		'inventory_fob'    => [
			'otd'          => 'Over the Counter',
			'over counter' => 'Over the Counter',
			'script'       => 'Prescription',
			'pres.'        => 'Prescription'
		],
		'inventory_serial' => [
			'vacine' => 'Vaccine',
			'vacc'   => 'Vaccine',
			'analg'  => 'Analgesic'
		]
	];

	/**
	 * === Advanced Dictionary Instructions ===
	 *
	 * Dictionary for the type-specific words (if using Advanced Inventory Manager) to be remapped.
	 *
	 * The structure is similar to $common above, but with an additional "layer" of keys which
	 * represent the type ID.
	 *
	 * Structure: [type_id => [ field_name => [ wrong => correct, wrong2 => correct2 ] ] ]
	 *
	 * @var array
	 */
	public static $by_type = [
		1 => [
			'inventory_fob'    => [
				'otd' => 'Over the Counter',
			],
			'inventory_number' => [
				'some phrase'    => 'The Correct Phrase',
				'another phrase' => 'The Correct Phrase'
			]
		],
		2 => [
			'inventory_name' => [
				'wrong term' => 'Correct Term'
			]
		]
	];
}

class WPIMFilterImport {
	/**
	 * Determines if "common" mappings will override "by type" mappings or not.
	 *
	 * @var bool
	 */
	private $common_overrides_by_type = TRUE;

	/**
	 * === Date Normalizing Instructions ===
	 *
	 * Array of field(s) that should be treated as dates,
	 * and will be run through the "normalize date" method.
	 *
	 * This should permit any / all dates that are imported,
	 * regardless of format, to be "normalized" to a standard
	 * format.
	 *
	 * To NOT normalize ANY dates, set this value to an empty array:
	 * private $date_fields = [];
	 *
	 * ALSO, you can change the format with the $date_format variable below
	 *
	 * LASTLY, you can also change whether dates in the past are permitted by
	 * adjusting $permit_past_dates below.
	 *
	 * @var array
	 */
	private $date_fields = [ 'inventory_make' ];

	/**
	 * === Date Normalizing ===
	 *
	 * String that represents the desired output for "standardized" date
	 * format.
	 *
	 * This leverages the PHP built-in date functionality.
	 * For help with different formats: http://php.net/manual/en/function.date.php
	 *
	 * @var string|bool
	 */
	private $date_format = 'Y-m-d';

	/**
	 * === Date Normalizing ===
	 *
	 * When processing dates in the filter, this flag indicates whether or not
	 * errors should be displayed for dates that are in the past.
	 *
	 * @var bool
	 */
	private $permit_past_dates = FALSE;

	public function __construct() {
		$common_priority = ( $this->common_overrides_by_type ) ? 20 : 0;
		// filter to restrict "add options if don't exist" on import form
		add_filter( 'wpim_ie_create_categories', [ $this, 'wpim_ie_create_categories' ] );
		// filter to restrict "add options if don't exist" on import form
		add_filter( 'wpim_ie_aim_create_new_options', [ $this, 'wpim_ie_aim_create_new_options' ] );
		// filter to update value(s) on import for non-AIM installs
		add_filter( 'wpim_ie_insert_value', [ $this, 'wpim_ie_insert_value' ], $common_priority, 3 );
		// filter to update value(s) on import for AIM installs
		add_filter( 'wpim_ie_aim_insert_value', [ $this, 'wpim_ie_aim_insert_value' ], 10, 4 );
	}


	/**
	 * === Control Categories Instructions ===
	 *
	 * Restricts the "Create New categories" checkboxes from appearing based on logged-in user role.
	 * To adjust, add roles to the $permitted_roles array in the function below.
	 *
	 * To ignore this and ALWAYS enable, simply uncomment the "return TRUE;" line at the top of the function.
	 *
	 * @param bool $allow
	 *
	 * @return bool
	 */
	public function wpim_ie_create_categories( $allow ) {
		// to permit ALL users, uncomment the below line (remove the "//" from the beginning)
		// return TRUE;
		$user  = wp_get_current_user();
		$roles = $user->roles;

		// to add or change roles, add or change the role names in the array below
		$permitted_roles = [ 'administrator', 'editor' ];

		if ( ! empty( $roles ) ) {
			$role = reset( $roles );
			if ( in_array( $role, $permitted_roles ) ) {
				return TRUE;
			}
		}

		return $allow;
	}

	/**
	 * === Control AIM Options Instructions ===
	 *
	 * Only matters if Advanced Inventory Manager is installed, and only affects lists that are set to "Radio" or
	 * "Dropdown"
	 *
	 * Restricts the "Add New options" checkboxes from appearing based on logged-in user role.
	 * To adjust, add roles to the $permitted_roles array in the function below.
	 *
	 * To ignore this and ALWAYS enable, simply uncomment the "return TRUE;" line at the top of the function.
	 *
	 * @param bool $allow
	 *
	 * @return bool
	 */
	public function wpim_ie_aim_create_new_options( $allow ) {
		// to permit ALL users, uncomment the below line (remove the "//" from the beginning)
		// return TRUE;
		$user  = wp_get_current_user();
		$roles = $user->roles;

		// to add or change roles, add or change the role names in the array below
		$permitted_roles = [ 'administrator', 'editor' ];

		if ( ! empty( $roles ) ) {
			$role = reset( $roles );
			if ( in_array( $role, $permitted_roles ) ) {
				return TRUE;
			}
		}

		return $allow;
	}

	/**
	 * Filter that is run on every value that is imported from Import Export.
	 * This filter runs when Advanced Inventory Manager is not installed, AND
	 * if Advanced Inventory Manager IS installed, this can be used to override
	 * "common" values.
	 *
	 * Note that this function is not / can not be aware of the AIM type....
	 *
	 * @param string $value
	 * @param string $field
	 * @param array  $map
	 *
	 * @return mixed
	 */
	public function wpim_ie_insert_value( $value, $field, $map ) {
		// Intercept any date fields to ensure proper formatting
		if ( in_array( $field, $this->date_fields ) ) {
			return $this->normalize_date( $value );
		}

		return $this->common_dictionary( $field, $value );
	}

	/**
	 * Filter that is run on every value that is imported from Import Export
	 * ONLY IF Advanced Inventory Manager is installed.
	 *
	 * Note this function IS AWARE of what AIM type is being imported,
	 * so can be leveraged for type-specific dictionary mapping.
	 *
	 * @param string $value
	 * @param string $field
	 * @param array  $map
	 *
	 * @return mixed
	 */
	public function wpim_ie_aim_insert_value( $value, $field, $map, $type_id ) {
		return $this->by_type_dictionary( $type_id, $field, $value );
	}

	/**
	 * Utility functions to search the "common" dictionary and return the word if exists.
	 * This function searches only the "common" terms.
	 *
	 * @param string $field
	 * @param string $word
	 *
	 * @return mixed
	 */
	private function common_dictionary( $field, $word ) {
		// call the lookup function, passing in the common dictionary
		return $this->lookup( $field, $word, WPIMFilterDictionary::$common );
	}

	/**
	 * Utility functions to search the "by type" dictionaries and return the word if exists.
	 * This function searches only the "by_type" terms.
	 *
	 * @param string $field
	 * @param string $word
	 *
	 * @return mixed
	 */
	private function by_type_dictionary( $type_id, $field, $word ) {
		// ensure the type_id is a number, to ensure finding if exists
		$type_id = (int) $type_id;

		// if there are no entries for the type in the dictionary, then return the original word
		if ( ! array_key_exists( $type_id, WPIMFilterDictionary::$by_type ) ) {
			return $word;
		}

		// call the lookup function, passing in the type-specific dictionary
		return self::lookup( $field, $word, WPIMFilterDictionary::$by_type[ $type_id ] );
	}

	/**
	 * Utility used by the other functions in this class to perform the lookup.
	 *
	 * @param string $field
	 * @param string $word
	 * @param array  $dictionary
	 *
	 * @return mixed
	 */
	private function lookup( $field, $word, $dictionary ) {
		// if the field isn't mapped in the dictionary, just return the word unchanged
		if ( ! array_key_exists( $field, $dictionary ) ) {
			return $word;
		}

		// load just the dictionary for just the specified field
		$dictionary = $dictionary[ $field ];

		$key = strtolower( $word );
		// if the "wrong version" doesn't exist in the dictionary, just return the word unchanged
		if ( ! array_key_exists( $key, $dictionary ) ) {
			return $word;
		}

		// if this is reached, then the wrong word was found
		// return the correct version of the word
		return $dictionary[ $key ];
	}

	/**
	 * This function attempts to normalize dates, utilizing PHP's fairly magical date functionality.
	 * Note that if PHP can't handle the date, there can be added special logic to parse / break down
	 * the date in order to handle an uncommon structure.
	 *
	 * IMPORTANT DISCLAIMER:
	 * The value '05-12-2018' will result in December 5, 2018 - but COULD ALSO mean May 12, 2018.
	 * There is no good means for detecting which was intended.
	 * If you find that you are battling this scenario, let us know.  We can partner with you
	 * and potentially find a workable solution.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function normalize_date( $value ) {
		// attempt to grok the date using PHP's magic....
		$timestamp = strtotime( $value );

		if ( FALSE === $timestamp ) {
			echo '<div class="error"><p><strong>ERROR!</strong> The date ' . $value . ' could not be handled by the WP Inventory Custom Filters plugin!</p></div>';
		}

		// If the timestamp is in the past, then may want to do custom processing
		if ( $timestamp <= time() && ! $this->permit_past_dates ) {
			echo '<div class="error"><p><strong>WARNING!</strong> The date ' . $value . ' was in the past!</p></div>';
			return $value;
		}

		/**
		 * NOTE: while the above two checks succeeded, it is possible that the date was NOT properly
		 * processed.  For example, '05-12-2018' will result in December 5, 2018 (but COULD ALSO mean May 12, 2018).
		 * There's no good method to determine which was correct.
		 *
		 * PHP's strtotime function used above expects `-` to be an international format, d-m-y - whereas `/` represents US format, m/d/y
		 */
		return date( $this->date_format, $timestamp );
	}
}

new WPIMFilterImport();
