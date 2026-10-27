<?php
/**
 * A single site scan result.
 *
 * @package Linguator
 */

namespace LinguatorPro\Modules\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object returned by checks.
 *
 * @since 2.3.0
 */
class Result {

	const PASS     = 'pass';
	const INFO     = 'info';
	const WARNING  = 'warning';
	const CRITICAL = 'critical';

	/**
	 * @var string
	 */
	public $id;

	/**
	 * @var string One of the status constants.
	 */
	public $status;

	/**
	 * @var string Category label, e.g. "Language Setup".
	 */
	public $group;

	/**
	 * @var string Report section: content, urls or site.
	 */
	public $section = 'site';

	/**
	 * @var string Human title.
	 */
	public $label;

	/**
	 * @var string One-line explanation.
	 */
	public $message = '';

	/**
	 * @var string Admin URL where the issue can be fixed.
	 */
	public $fix_url = '';

	/**
	 * @var string Label of the fix link.
	 */
	public $fix_label = '';

	/**
	 * @var array[] Structured rows listing each affected item.
	 */
	public $details = array();

	/**
	 * @var bool Whether the detail rows were capped during collection.
	 */
	public $details_truncated = false;

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id      Unique id.
	 * @param string $status  Status.
	 * @param string $group   Category.
	 * @param string $label   Title.
	 * @param string $message Explanation.
	 */
	public function __construct( string $id, string $status, string $group, string $label, string $message = '' ) {
		$this->id      = $id;
		$this->status  = $status;
		$this->group   = $group;
		$this->label   = $label;
		$this->message = $message;
	}

	/**
	 * Adds a fix link.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url   Admin URL.
	 * @param string $label Link text.
	 * @return Result
	 */
	public function with_fix( string $url, string $label = '' ): Result {
		$this->fix_url   = $url;
		$this->fix_label = '' !== $label ? $label : __( 'Fix', 'linguator-pro' );
		return $this;
	}

	/**
	 * Attaches detail rows.
	 *
	 * Each row: label, message, action_url, action_label, ignore_key.
	 *
	 * @since 2.3.0
	 *
	 * @param array[] $details   Detail rows.
	 * @param bool    $truncated Whether more rows exist than were collected.
	 * @return Result
	 */
	public function with_details( array $details, bool $truncated = false ): Result {
		$this->details           = $details;
		$this->details_truncated = $truncated;
		return $this;
	}

	/**
	 * Exports the result for JSON storage.
	 *
	 * @since 2.3.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'status'            => $this->status,
			'group'             => $this->group,
			'section'           => $this->section,
			'label'             => $this->label,
			'message'           => $this->message,
			'fix_url'           => $this->fix_url,
			'fix_label'         => $this->fix_label,
			'details'           => $this->details,
			'details_truncated' => $this->details_truncated,
		);
	}
}
