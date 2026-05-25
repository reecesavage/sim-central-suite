<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * JS-emitting DOM-manipulation DSL used by the suite's event listeners
 * to inject HTML before/after specific elements in Nova's stock views
 * (Nova's "view output" event only lets you append to $event['output'],
 * so we emit a small <script> block that does the placement client-side).
 *
 * Vendored from the standalone `jquery` Nova extension so the suite has
 * no dependency on that extension — other extensions on the site can
 * still use the standalone independently. The standalone hasn't been
 * touched in years and is unlikely to change, but if it ever does our
 * copy is intentionally frozen at this shape:
 *
 *     \nova_ext_sim_central\Generator::select('#timeline')->closest('p')->before($html);
 *
 * Usage in events mirrors the standalone's
 * `$this->extension['jquery']['generator']->select(...)` exactly.
 */
class Generator
{
	public static function select($selector)
	{
		return new GeneratorChain($selector);
	}
}

class GeneratorChain
{
	public $selector;
	public $chain = array();

	public $supported_tags = array(
		'first',
		'last',
		'before',
		'after',
		'append',
		'prepend',
		'html',
		'text',
		'closest',
		'remove',
	);

	public function __construct($selector)
	{
		$this->selector = $selector;
	}

	public function __call($method, $args = array())
	{
		if (in_array($method, $this->supported_tags, true)) {
			$this->chain[] = array(
				'type' => 'method',
				'name' => $method,
				'args' => $args,
			);
		} else {
			show_error('Unsupported '.__CLASS__.' method: '.$method);
		}
		return $this;
	}

	public function __toString()
	{
		return '<script type="text/javascript">'.implode('.', array_merge(
			array('$('.json_encode($this->selector).')'),
			array_map(function($entry){
				switch ($entry['type']) {
					case 'method':
						return $entry['name'].'('.implode(',', array_map(function($arg){
							return json_encode($arg);
						}, $entry['args'])).')';
				}
			}, $this->chain)
		)).'</script>';
	}
}
