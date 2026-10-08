<?php
/**
 * Drift: Surface Hub — the schema.
 *
 * THE single source of truth for every artist's content. Add a field here
 * and every artist on the hub has it immediately: no template base, no
 * migrate script. Names and types match the Encore Website map
 * (maps/encore.php) so artist websites sync from the hub without any
 * change to their product map.
 *
 * Field keys:
 *   type        field type (singleLineText, multilineText, richText,
 *               url, email, phoneNumber, multipleAttachments, checkbox, date,
 *               dateTime, number, currency, rating, singleSelect,
 *               multipleSelects, multipleRecordLinks, formula, createdTime)
 *   choices     select options
 *   help        hint shown under the field in the hub
 *   hidden      true = not shown in the hub editor (system fields)
 *   readonly    true = shown, not editable
 *   link        multipleRecordLinks: [ 'table' => …, 'inverse' => true ] — an
 *               inverse link is computed from the other side, never stored
 *   compute     formula/createdTime: callable( array $fields, array $record ): mixed
 *   group       section heading in the editor (singleton tables)
 *   hub_only    true = used by the hub itself only: left out of the website
 *               API, and editing it doesn't flag unpublished changes
 *
 * Table keys:
 *   label       hub navigation label
 *   icon        emoji in the hub navigation
 *   singleton   one record per artist (Site Settings)
 *   writable    the website may create records here (forms)
 *   columns     fields shown in the hub list view
 *   sort        default hub list order: [ field, asc|desc ]
 *   status      checkbox that controls whether a row shows on the website
 *   add_label   "Add gig" etc.
 *
 * Renaming a field? Add 'was' => 'Old Name' and the store reads old values
 * under the new name.
 *
 * @package Drift_Hub
 */

defined( 'ABSPATH' ) || exit;

$t    = static fn( array $extra = [] ) => [ 'type' => 'singleLineText' ] + $extra;
$long = static fn( array $extra = [] ) => [ 'type' => 'multilineText' ] + $extra;
$rich = static fn( array $extra = [] ) => [ 'type' => 'richText', 'help' => 'Supports **bold**, _italic_, [links](https://…) and - lists.' ] + $extra;
$url  = static fn( array $extra = [] ) => [ 'type' => 'url' ] + $extra;
$file = static fn( array $extra = [] ) => [ 'type' => 'multipleAttachments' ] + $extra;
$chk  = static fn( array $extra = [] ) => [ 'type' => 'checkbox' ] + $extra;
$date = static fn( array $extra = [] ) => [ 'type' => 'date' ] + $extra;
$int  = static fn( array $extra = [] ) => [ 'type' => 'number', 'precision' => 0 ] + $extra;
$show = [ 'type' => 'checkbox', 'help' => 'Untick to hide it from the website without deleting it.', 'default' => true ];

return [
	'label'  => 'Drift: Surface Hub',
	'tables' => [

		'Site Settings' => [
			'label'     => 'Site settings',
			'icon'      => '⚙️',
			'singleton' => true,
			'fields'    => [
				'Artist Name'      => $t( [ 'group' => 'About' ] ),
				'Tagline'          => $t( [ 'group' => 'About' ] ),
				'Genre'            => $t( [ 'group' => 'About' ] ),
				'Hometown'         => $t( [ 'group' => 'About' ] ),
				'Short Bio'        => $long( [ 'group' => 'About', 'help' => 'About 50 words. Used in cards and search results.' ] ),
				'Full Bio'         => $rich( [ 'group' => 'About' ] ),
				'SEO Description'  => $long( [ 'group' => 'About', 'help' => 'About 155 characters, for Google.' ] ),
				'Hub Avatar'       => $file( [ 'group' => 'Images', 'max' => 1, 'hub_only' => true, 'help' => 'The artist\'s picture in the hub roster. Square, at least 150px. Hub only: not used on the website. If empty, the Logo is used.' ] ),
				'Logo'             => $file( [ 'group' => 'Images', 'max' => 1, 'help' => 'Square or wide PNG with a transparent background works best.' ] ),
				'Logo (Light)'     => $file( [ 'group' => 'Images', 'max' => 1, 'help' => 'For dark backgrounds.' ] ),
				'Hero Image'       => $file( [ 'group' => 'Images', 'max' => 1, 'help' => 'Wide photo, about 2400px across.' ] ),
				'Hero Video URL'   => $url( [ 'group' => 'Images', 'help' => 'Optional MP4/WebM background video.' ] ),
				'Press Kit PDF'    => $file( [ 'group' => 'Images', 'max' => 1 ] ),
				'Primary Colour'   => $t( [ 'group' => 'Colours', 'format' => 'colour', 'help' => 'Hex code, e.g. #ee4367' ] ),
				'Secondary Colour' => $t( [ 'group' => 'Colours', 'format' => 'colour' ] ),
				'Booking Email'    => [ 'type' => 'email', 'group' => 'Contact', 'help' => 'Booking enquiries from the website are emailed here.' ],
				'Management Email' => [ 'type' => 'email', 'group' => 'Contact' ],
				'Press Email'      => [ 'type' => 'email', 'group' => 'Contact' ],
				'Mailing List URL' => $url( [ 'group' => 'Contact', 'help' => 'Only if you use an external sign-up page.' ] ),
				'Instagram'        => $url( [ 'group' => 'Links' ] ),
				'Facebook'         => $url( [ 'group' => 'Links' ] ),
				'TikTok'           => $url( [ 'group' => 'Links' ] ),
				'YouTube'          => $url( [ 'group' => 'Links' ] ),
				'X'                => $url( [ 'group' => 'Links' ] ),
				'Spotify'          => $url( [ 'group' => 'Links' ] ),
				'Apple Music'      => $url( [ 'group' => 'Links' ] ),
				'Bandcamp'         => $url( [ 'group' => 'Links' ] ),
				'SoundCloud'       => $url( [ 'group' => 'Links' ] ),
				'Live Embed'       => $long( [ 'group' => 'Embeds', 'help' => 'Optional. Paste embed code from your gig listing service; it replaces the gig list.' ] ),
				'Merch Embed'      => $long( [ 'group' => 'Embeds', 'help' => 'Optional. Paste shop embed code; it replaces the merch grid.' ] ),
				'Publish'          => $chk( [ 'hidden' => true ] ),
				'Last Published'   => [ 'type' => 'dateTime', 'hidden' => true ],
			],
		],

		'Gigs' => [
			'label'     => 'Gigs',
			'icon'      => '🎟️',
			'add_label' => 'Add gig',
			'columns'   => [ 'Date', 'Venue', 'City', 'Status' ],
			'sort'      => [ 'Date', 'asc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Gig'          => [ 'type' => 'formula', 'compute' => static fn( $f ) => trim( implode( ', ', array_filter( [ $f['Venue'] ?? '', $f['City'] ?? '' ] ) ) ) ],
				'Date'         => $date( [ 'required' => true ] ),
				'Doors'        => $t( [ 'help' => 'e.g. 19:30' ] ),
				'Venue'        => $t( [ 'required' => true ] ),
				'City'         => $t(),
				'Country'      => $t(),
				'Ticket URL'   => $url(),
				'Status'       => [ 'type' => 'singleSelect', 'choices' => [ 'On Sale', 'Few Left', 'Sold Out', 'Free', 'Cancelled', 'Announced' ], 'default' => 'On Sale' ],
				'Support'      => $t(),
				'Festival'     => $chk(),
				'Notes'        => $rich(),
				'Show on Site' => $show,
			],
		],

		'Releases' => [
			'label'     => 'Music',
			'icon'      => '💿',
			'add_label' => 'Add release',
			'columns'   => [ 'Artwork', 'Title', 'Type', 'Release Date', 'Featured' ],
			'sort'      => [ 'Release Date', 'desc' ],
			'fields'    => [
				'Title'           => $t( [ 'required' => true ] ),
				'Type'            => [ 'type' => 'singleSelect', 'choices' => [ 'Album', 'EP', 'Single', 'Live', 'Remix' ] ],
				'Release Date'    => $date(),
				'Artwork'         => $file( [ 'max' => 1, 'help' => 'Square, 3000px.' ] ),
				'Label'           => $t(),
				'Description'     => $rich(),
				'Spotify URL'     => $url(),
				'Apple Music URL' => $url(),
				'Bandcamp URL'    => $url(),
				'YouTube URL'     => $url(),
				'Pre-save URL'    => $url( [ 'help' => 'For releases that aren\'t out yet.' ] ),
				'Featured'        => $chk( [ 'help' => 'Shown as "Out now" on the home page.' ] ),
				'Tracks'          => [ 'type' => 'multipleRecordLinks', 'link' => [ 'table' => 'Tracks', 'inverse' => 'Release', 'order' => 'Track Number' ], 'readonly' => true, 'help' => 'Add tracks under Tracks and pick this release.' ],
			],
		],

		'Tracks' => [
			'label'     => 'Tracks',
			'icon'      => '🎵',
			'add_label' => 'Add track',
			'columns'   => [ 'Release', 'Track Number', 'Title', 'Duration' ],
			'sort'      => [ 'Track Number', 'asc' ],
			'fields'    => [
				'Title'        => $t( [ 'required' => true ] ),
				'Release'      => [ 'type' => 'multipleRecordLinks', 'link' => [ 'table' => 'Releases' ], 'max' => 1 ],
				'Track Number' => $int(),
				'Duration'     => $t( [ 'help' => 'm:ss, e.g. 3:41' ] ),
				'Writers'      => $t(),
				'Preview URL'  => $url(),
				'Lyrics'       => $rich(),
			],
		],

		'Members' => [
			'label'     => 'Band',
			'icon'      => '🎸',
			'add_label' => 'Add member',
			'columns'   => [ 'Photo', 'Name', 'Role' ],
			'sort'      => [ 'Order', 'asc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Name'         => $t( [ 'required' => true ] ),
				'Role'         => $t(),
				'Bio'          => $rich(),
				'Photo'        => $file( [ 'max' => 1 ] ),
				'Instagram'    => $url(),
				'Order'        => $int(),
				'Show on Site' => $show,
			],
		],

		'News' => [
			'label'     => 'News',
			'icon'      => '📰',
			'add_label' => 'Add news',
			'columns'   => [ 'Date', 'Headline' ],
			'sort'      => [ 'Date', 'desc' ],
			'status'    => 'Published',
			'fields'    => [
				'Headline'  => $t( [ 'required' => true ] ),
				'Date'      => [ 'type' => 'dateTime', 'help' => 'A future date schedules the post.' ],
				'Summary'   => $long(),
				'Body'      => $rich(),
				'Image'     => $file( [ 'max' => 1 ] ),
				'Published' => [ 'type' => 'checkbox', 'help' => 'Untick to keep it as a draft.', 'default' => true ],
			],
		],

		'Gallery' => [
			'label'     => 'Photos',
			'icon'      => '📷',
			'add_label' => 'Add photo',
			'columns'   => [ 'Photo', 'Caption', 'Album' ],
			'sort'      => [ 'Order', 'asc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Title'        => [ 'type' => 'formula', 'compute' => static fn( $f ) => ( '' !== trim( (string) ( $f['Caption'] ?? '' ) ) ) ? $f['Caption'] : 'Photo' ],
				'Photo'        => $file( [ 'max' => 1, 'required' => true ] ),
				'Caption'      => $t(),
				'Album'        => [ 'type' => 'multipleSelects', 'choices' => [ 'Live', 'Press', 'Studio' ] ],
				'Credit'       => $t( [ 'help' => 'Photographer' ] ),
				'Order'        => $int(),
				'Show on Site' => $show,
			],
		],

		'Videos' => [
			'label'     => 'Videos',
			'icon'      => '🎬',
			'add_label' => 'Add video',
			'columns'   => [ 'Title', 'Date', 'Featured' ],
			'sort'      => [ 'Date', 'desc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Title'        => $t( [ 'required' => true ] ),
				'Video URL'    => $url( [ 'required' => true, 'help' => 'YouTube or Vimeo link.' ] ),
				'Thumbnail'    => $file( [ 'max' => 1, 'help' => 'Optional — YouTube\'s own thumbnail is used otherwise.' ] ),
				'Date'         => $date(),
				'Featured'     => $chk(),
				'Show on Site' => $show,
			],
		],

		'Press' => [
			'label'     => 'Press',
			'icon'      => '🗞️',
			'add_label' => 'Add quote',
			'columns'   => [ 'Publication', 'Quote', 'Rating' ],
			'sort'      => [ 'Order', 'asc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Publication'  => $t( [ 'required' => true ] ),
				'Quote'        => $long(),
				'Author'       => $t(),
				'Link'         => $url(),
				'Logo'         => $file( [ 'max' => 1 ] ),
				'Rating'       => [ 'type' => 'rating', 'max' => 5 ],
				'Date'         => $date(),
				'Order'        => $int(),
				'Show on Site' => $show,
			],
		],

		'Merch' => [
			'label'     => 'Merch',
			'icon'      => '👕',
			'add_label' => 'Add item',
			'columns'   => [ 'Image', 'Item', 'Price', 'Badge' ],
			'sort'      => [ 'Order', 'asc' ],
			'status'    => 'Show on Site',
			'fields'    => [
				'Item'         => $t( [ 'required' => true ] ),
				'Image'        => $file( [ 'max' => 1 ] ),
				'Price'        => [ 'type' => 'currency', 'symbol' => '£' ],
				'Store URL'    => $url(),
				'Badge'        => [ 'type' => 'singleSelect', 'choices' => [ 'New', 'Limited', 'Sold Out' ] ],
				'Order'        => $int(),
				'Show on Site' => $show,
			],
		],

		'Enquiries' => [
			'label'    => 'Inbox',
			'icon'     => '📥',
			'writable' => true,
			'columns'  => [ 'Received', 'Name', 'Enquiry Type', 'Status' ],
			'sort'     => [ 'Received', 'desc' ],
			'fields'   => [
				'Name'         => $t( [ 'readonly' => true ] ),
				'Email'        => [ 'type' => 'email', 'readonly' => true ],
				'Phone'        => [ 'type' => 'phoneNumber', 'readonly' => true ],
				'Enquiry Type' => [ 'type' => 'singleSelect', 'choices' => [ 'Booking', 'Festival', 'Wedding / private event', 'Press', 'Other' ], 'readonly' => true ],
				'Event Date'   => $t( [ 'readonly' => true ] ),
				'Location'     => $t( [ 'readonly' => true ] ),
				'Message'      => $long( [ 'readonly' => true ] ),
				'Page'         => $url( [ 'readonly' => true ] ),
				'Status'       => [ 'type' => 'singleSelect', 'choices' => [ 'New', 'Replied', 'Booked', 'Declined' ], 'default' => 'New' ],
				'Received'     => [ 'type' => 'createdTime' ],
			],
		],

		'Subscribers' => [
			'label'    => 'Mailing list',
			'icon'     => '✉️',
			'writable' => true,
			'columns'  => [ 'Joined', 'Email', 'Name' ],
			'sort'     => [ 'Joined', 'desc' ],
			'fields'   => [
				'Email'  => [ 'type' => 'email', 'required' => true ],
				'Name'   => $t(),
				'Joined' => [ 'type' => 'createdTime' ],
			],
		],
	],
];
