<?php
/**
 * @file
 * @license https://opensource.org/licenses/Apache-2.0 Apache-2.0
 */

namespace Wikimedia\CSS\Grammar;

use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Objects\SimpleBlock;
use Wikimedia\CSS\Objects\CSSFunction;

/**
 * Base class for grammar matchers.
 *
 * The [CSS Syntax Level 3][SYN3] and [Values Level 3][VAL3] specifications use
 * a mostly context-free grammar to define what things like selectors and
 * property values look like. The Matcher classes allow for constructing an
 * object that will determine whether a ComponentValueList actually matches
 * this grammar.
 *
 * [SYN3]: https://www.w3.org/TR/2014/CR-css-syntax-3-20140220/
 * [VAL3]: https://www.w3.org/TR/2016/CR-css-values-3-20160929/
 */
abstract class Matcher {

	/** @var string|null Name to set on Match objects */
	protected $captureName = null;

	/**
	 * @var array Default options for self::match()
	 *  - skip-whitespace: (bool) Allow whitespace in between any two tokens
	 *  - nonterminal: (bool) Don't require the whole of $values is matched
	 *  - mark-significance: (bool) On a successful match, replace T_WHITESPACE
	 *    tokens as necessary to indicate significant whitespace.
	 */
	protected $defaultOptions = [
		'skip-whitespace' => true,
		'nonterminal' => false,
		'mark-significance' => false,
	];

	/**
	 * Create an instance.
	 * @param mixed ... See static::__construct()
	 * @return static
	 */
	public static function create() {
		// @todo Once we drop support for PHP 5.5, just do this:
		//  public static function create( ...$args ) {
		//      return new static( ...$args );
		//  }

		$args = func_get_args();
		switch ( count( $args ) ) {
			case 0:
				return new static();
			case 1:
				return new static( $args[0] );
			case 2:
				return new static( $args[0], $args[1] );
			case 3:
				return new static( $args[0], $args[1], $args[2] );
			case 4:
				return new static( $args[0], $args[1], $args[2], $args[3] );
			default:
				// Slow, but all the existing Matchers have a max of 4 args.
				$rc = new \ReflectionClass( static::class );
				return $rc->newInstanceArgs( $args );
		}
	}

	/**
	 * Return a copy of this matcher that will capture its matches
	 *
	 * A "capturing" Matcher will produce Matches that return a value from the
	 * Match::getName() method. The Match::getCapturedMatches() method may be
	 * used to retrieve them from the top-level Match.
	 *
	 * The concept is similar to capturing groups in PCRE and other regex
	 * languages.
	 *
	 * @param string|null $captureName Name to apply to captured Match objects
	 * @return static
	 */
	public function capture( $captureName ) {
		$ret = clone( $this );
		$ret->captureName = $captureName;
		return $ret;
	}

	/**
	 * Match against a list of ComponentValues
	 * @param ComponentValueList $values
	 * @param array $options Matching options, see self::$defaultOptions
	 * @return Match|null
	 */
	public function match( ComponentValueList $values, array $options = [] ) {
		$options += $this->getDefaultOptions();
		$start = $this->next( $values, -1, $options );
		$l = count( $values );
		foreach ( $this->generateMatches( $values, $start, $options ) as $match ) {
			if ( $match->getNext() === $l || $options['nonterminal'] ) {
				if ( $options['mark-significance'] ) {
					$significantWS = self::collectSignificantWhitespace( $match );
					self::markSignificantWhitespace( $values, $match, $significantWS, $match->getNext() );
				}
				return $match;
			}
		}
		return null;
	}

	/**
	 * Collect any 'significantWhitespace' matches
	 * @param Match $match
	 * @param Token[]|null &$ret
	 * @return Token[]
	 */
	private static function collectSignificantWhitespace( Match $match, &$ret = [] ) {
		if ( $match->getName() === 'significantWhitespace' ) {
			$ret = array_merge( $ret, $match->getValues() );
		}
		foreach ( $match->getCapturedMatches() as $m ) {
			self::collectSignificantWhitespace( $m, $ret );
		}
		return $ret;
	}

	/**
	 * Mark whitespace as significant or not
	 * @param ComponentValueList $list
	 * @param Match $match
	 * @param Token[] $significantWS
	 * @param int $end
	 */
	private static function markSignificantWhitespace( $list, $match, $significantWS, $end ) {
		for ( $i = 0; $i < $end; $i++ ) {
			$cv = $list[$i];
			if ( $cv instanceof Token && $cv->type() === Token::T_WHITESPACE ) {
				$significant = in_array( $cv, $significantWS, true );
				if ( $significant !== $cv->significant() ) {
					$list[$i] = $cv->copyWithSignificance( $significant );
					$match->fixWhitespace( $cv, $list[$i] );
				}
			} elseif ( $cv instanceof CSSFunction || $cv instanceof SimpleBlock ) {
				self::markSignificantWhitespace(
					$cv->getValue(), $match, $significantWS, count( $cv->getValue() )
				);
			}
		}
	}

	/**
	 * Fetch the default options for this Matcher
	 * @return array See self::$defaultOptions
	 */
	public function getDefaultOptions() {
		return $this->defaultOptions;
	}

	/**
	 * Set the default options for this Matcher
	 * @param array $options See self::$defaultOptions
	 * @return static $this
	 */
	public function setDefaultOptions( array $options ) {
		$this->defaultOptions = $options + $this->defaultOptions;
		return $this;
	}

	/**
	 * Find the next ComponentValue in the input, possibly skipping whitespace
	 * @param ComponentValueList $values Input values
	 * @param int $start Current position in the input. May be -1, in which
	 *  case the first position in the input should be returned.
	 * @param array $options See self::$defaultOptions
	 * @return int Next token index
	 */
	protected function next( ComponentValueList $values, $start, array $options ) {
		$skipWS = $options['skip-whitespace'];

		$i = $start;
		$l = count( $values );
		do {
			$i++;
		} while ( $skipWS && $i < $l &&
			$values[$i] instanceof Token && $values[$i]->type() === Token::T_WHITESPACE
		);
		return $i;
	}

	/**
	 * Create a Match
	 * @param ComponentValueList $list
	 * @param int $start
	 * @param int $end First position after the match
	 * @param Match|null $submatch Submatch, for capturing. If $submatch itself
	 *  named it will be kept as a capture in the returned Match, otherwise its
	 *  captured matches (if any) as returned by getCapturedMatches() will be
	 *  kept as captures in the returned Match.
	 * @param array $stack Stack from which to fetch more submatches for
	 *  capturing (see $submatch). The stack is expected to be an array of
	 *  arrays, with the first element of each subarray being a Match.
	 * @return Match
	 */
	protected function makeMatch(
		ComponentValueList $list, $start, $end, Match $submatch = null, array $stack = []
	) {
		$matches = array_column( $stack, 0 );
		$matches[] = $submatch;

		$keptMatches = [];
		while ( $matches ) {
			$m = array_shift( $matches );
			if ( !$m instanceof Match ) {
				// skip it, probably null
			} elseif ( $m->getName() !== null ) {
				$keptMatches[] = $m;
			} elseif ( $m->getCapturedMatches() ) {
				$matches = array_merge( $m->getCapturedMatches(), $matches );
			}
		}

		return new Match( $list, $start, $end - $start, $this->captureName, $keptMatches );
	}

	/**
	 * Match against a list of ComponentValues
	 *
	 * The job of a Matcher is to determine all the ways its particular grammar
	 * fragment can consume ComponentValues starting at a particular location
	 * in the ComponentValueList, represented by returning Match objects. For
	 * example, a matcher implementing `IDENT*` at a starting position where
	 * there are three IDENT tokens in a row would be able to match 0, 1, 2, or
	 * all 3 of those IDENT tokens, and therefore should return an iterator
	 * over that set of Match objects.
	 *
	 * Some matchers take other matchers as input, for example `IDENT*` is
	 * probably going to be implemented as a matcher for `*` that repeatedly
	 * applies a matcher for `IDENT`. The `*` matcher would call the `IDENT`
	 * matcher's generateMatches() method directly.
	 *
	 * Most Matchers implement this method as a generator so as to not build up
	 * the full set of results when it's reasonably likely the caller is going
	 * to terminate early.
	 *
	 * @param ComponentValueList $values
	 * @param int $start Starting position in $values
	 * @param array $options See self::$defaultOptions.
	 *  Always use the options passed in, don't use $this->defaultOptions yourself.
	 * @return \Iterator<Match> Iterates over the set of Match objects
	 *  defining all the ways this matcher can match.
	 */
	abstract protected function generateMatches( ComponentValueList $values, $start, array $options );
}
