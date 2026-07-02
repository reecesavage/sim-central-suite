<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * JS-emitting DOM-manipulation DSL used by the suite's event listeners
 * to inject HTML before/after specific elements in Nova's stock views
 * (Nova's "view output" event only lets you append to $event['output'],
 * so we emit a small <script> block that does the placement client-side).
 *
 * The DSL shape comes from the standalone `jquery` Nova extension:
 *
 *     \nova_ext_sim_central\Generator::select('#timeline')->closest('p')->before($html);
 *
 * The emitted script, however, is plain vanilla JS - NOT jQuery. The
 * original jQuery-string emission silently did nothing on skins that
 * don't load jQuery on their public pages (LCARS et al.): `$` was
 * undefined, the script threw, and the injected block never appeared.
 * Admin pages always have jQuery, but the public join/contact/login
 * injections are entirely at the mercy of the skin, so the interpreter
 * below depends on nothing. Insertion executes any <script> tags inside
 * the injected HTML (matching jQuery's behaviour, which several
 * listeners rely on for their inline guards), and runs at
 * DOMContentLoaded when the target hasn't been parsed yet.
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
		// Compile the chain to a data program the inline interpreter walks:
		// { s: "<selector>", o: [["first"], ["before", "<html>"]] }
		$ops = array();
		foreach ($this->chain as $entry) {
			if ($entry['type'] !== 'method') {
				continue;
			}
			$op = array($entry['name']);
			if (array_key_exists(0, $entry['args'])) {
				$op[] = (string) $entry['args'][0];
			}
			$ops[] = $op;
		}
		// json_encode's default slash-escaping keeps any literal
		// "</script>" inside injected HTML from terminating this tag.
		$program = json_encode(array('s' => $this->selector, 'o' => $ops));

		// ins(): insert parsed HTML relative to an element. Scripts inside
		// the fragment are rebuilt via createElement so they execute on
		// insertion (template-parsed scripts are inert by spec).
		$js = 'var p='.$program.';'
			.'function ins(el,pos,html){'
			.'var t=document.createElement("template");t.innerHTML=html;var f=t.content;'
			.'var ss=f.querySelectorAll("script");'
			.'for(var i=0;i<ss.length;i++){var o=ss[i],n=document.createElement("script");'
			.'for(var j=0;j<o.attributes.length;j++){n.setAttribute(o.attributes[j].name,o.attributes[j].value);}'
			.'n.text=o.textContent;o.parentNode.replaceChild(n,o);}'
			.'if(pos==="before"){el.parentNode.insertBefore(f,el);}'
			.'else if(pos==="after"){el.parentNode.insertBefore(f,el.nextSibling);}'
			.'else if(pos==="prepend"){el.insertBefore(f,el.firstChild);}'
			.'else{el.appendChild(f);}}'
			.'function run(){'
			.'var els;try{els=Array.prototype.slice.call(document.querySelectorAll(p.s));}catch(e){return;}'
			.'for(var i=0;i<p.o.length;i++){var name=p.o[i][0],arg=p.o[i].length>1?p.o[i][1]:"";'
			.'if(name==="first"){els=els.slice(0,1);}'
			.'else if(name==="last"){els=els.slice(-1);}'
			.'else if(name==="closest"){var out=[];for(var j=0;j<els.length;j++){'
			.'var c=els[j].closest?els[j].closest(arg):null;if(c&&out.indexOf(c)===-1){out.push(c);}}els=out;}'
			.'else if(name==="before"||name==="after"||name==="append"||name==="prepend"){'
			.'for(var j=0;j<els.length;j++){ins(els[j],name,arg);}}'
			.'else if(name==="html"){for(var j=0;j<els.length;j++){els[j].innerHTML=arg;}}'
			.'else if(name==="text"){for(var j=0;j<els.length;j++){els[j].textContent=arg;}}'
			.'else if(name==="remove"){for(var j=0;j<els.length;j++){'
			.'if(els[j].parentNode){els[j].parentNode.removeChild(els[j]);}}}'
			.'}}'
			.'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}';

		return '<script type="text/javascript">(function(){'.$js.'})();</script>';
	}
}
