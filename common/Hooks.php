<?php
/**
 * Wikitext scripting infrastructure for MediaWiki: hooks.
 * Copyright (C) 2009-2012 Victor Vasiliev <vasilvv@gmail.com>
 * http://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * Hooks for the Scripting extension.
 */
class ScriptingHooks {
	/**
	 * Register parser hooks.
	 * @param $parser Parser
	 */
	public static function setupParserHook( &$parser ) {
		$parser->setFunctionHook( 'invoke', 'ScriptingHooks::callHook', SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'script', 'ScriptingHooks::transcludeHook', SFH_NO_HASH | SFH_OBJECT_ARGS );
		return true;
	}

	/**
	 * Called when the interpreter is to be reset.
	 * 
	 * @static
	 * @param  $parser Parser
	 * @return bool
	 */
	public static function clearState( &$parser ) {
		Scripting::resetParserEngine( $parser );
		return true;
	}

	/**
	 * Hook function for {{#invoke:module|func}}
	 *
	 * @param $parser Parser
	 * @param $frame PPFrame
	 * @param $args array
	 * @return string
	 */
	public static function callHook( &$parser, $frame, $args ) {
		if( count( $args ) < 2 ) {
			throw new ScriptingException( 'scripting-common-nofunction' );
		}

		$module = $parser->mStripState->unstripBoth( array_shift( $args ) );
		$function = $frame->expand( array_shift( $args ) );
		return self::doRunHook( $parser, $frame, $module, $function, $args );
	}

	/**
	 * Hook function for {{script:module}}
	 *
	 * @param $parser Parser
	 * @param $frame PPFrame
	 * @param $args
	 * @return string
	 */
	public static function transcludeHook( &$parser, $frame, $args ) {
		$module = $parser->mStripState->unstripBoth( array_shift( $args ) );
		return self::doRunHook( $parser, $frame, $module, 'main', $args );
	}

	/**
	 * @param $parser Parser
	 * @param $frame PPFrame
	 * @param $moduleName
	 * @param $functionName
	 * @param $args
	 * @return string
	 * @throws ScriptingException
	 */
	private static function doRunHook( $parser, $frame, $moduleName, $functionName, $args ) {
		wfProfileIn( __METHOD__ );
		
		try {
			$engine = Scripting::getParserEngine( $parser );
			$title = Title::makeTitleSafe( NS_MODULE, $moduleName );
			if ( !$title ) {
				throw new ScriptingException( 'scripting-common-nosuchmodule' );
			}
			$module = $engine->fetchModuleFromParser( $title );

			$functionObj = $module->getFunction( $functionName );
			if( !$functionObj ) {
				throw new ScriptingException( 'scripting-common-nosuchfunction' );
			}

			foreach( $args as &$arg ) {
				$arg = $frame->expand( $arg );
			}

			$result = $functionObj->call( $args, $frame );

			wfProfileOut( __METHOD__ );
			return trim( strval( $result ) );
		} catch( ScriptingException $e ) {
			$msg = $e->getMessage();
			wfProfileOut( __METHOD__ );
			return "<strong class=\"error\">{$msg}</strong>";
		}
	}

	/**
	 * Overrides the standard view for modules. Enables syntax highlighting when
	 * possible.
	 *
	 * @param $text string
	 * @param $title Title
	 * @param $output OutputPage
	 * @return bool
	 */
	public static function handleScriptView( $text, $title, $output ) {
		global $wgScriptingUseGeSHi;

		if( $title->getNamespace() == NS_MODULE ) {
			$engine = Scripting::newDefaultEngine();
			$language = $engine->getGeSHiLanguage();
			
			if( $wgScriptingUseGeSHi && $language ) {
				$geshi = SyntaxHighlight_GeSHi::prepare( $text, $language );
				$geshi->set_language( $language );
				if( $geshi instanceof GeSHi && !$geshi->error() ) {
					$code = $geshi->parse_code();
					if( $code ) {
						$output->addHeadItem( "source-{$language}", SyntaxHighlight_GeSHi::buildHeadItem( $geshi ) );
						$output->addHTML( "<div dir=\"ltr\">{$code}</div>" );
						return false;
					}
				}
			}

			// No GeSHi, or GeSHi can't parse it, use plain <pre>
			$output->addHTML( "<pre class=\"mw-code mw-script\" dir=\"ltr\">\n" );
			$output->addHTML( htmlspecialchars( $text ) );
			$output->addHTML( "\n</pre>\n" );
			return false;
		} else {
			return true;
		}
	}
	
	public static function getCodeLanguage( $title, &$lang ) {
		global $wgScriptingUseCodeEditor;
		if( $wgScriptingUseCodeEditor && $title->getNamespace() == NS_MODULE ) {
			$engine = Scripting::newDefaultEngine();
			if( $engine->getCodeEditorLanguage() ) {
				$lang = $engine->getCodeEditorLanguage();
				return false;
			}
		}
		
		return true;
	}

	/**
	 * Indicates that modules are not wikitext.
	 * @param $title Title
	 * @param $result
	 * @return bool
	 */
	public static function isWikitextPage( $title, &$result ) {
		if( $title->getNamespace() == NS_MODULE ) {
			$result = false;
			return false;
		}
		return true;
	}

	/**
	 * Adds report of number of evaluations by the single wikitext page.
	 * 
	 * @param $parser Parser
	 * @param $report
	 * @return bool
	 */
	public static function reportLimits( $parser, &$report ) {
		# FIXME
		global $wgScriptsLimits;
		$engine = Scripting::getParserEngine( $parser );
		$report .= $engine->getLimitReport();
		return true;
	}

	/**
	 * Adds the module namespaces.
	 */
	public static function addCanonicalNamespaces( &$list ) {
		$list[NS_MODULE] = 'Module';
		$list[NS_MODULE_TALK] = 'Module_talk';
		return true;
	}

	public static function validateScript( $editor, $text, $section, &$error ) {
		global $wgUser;
		$title = $editor->mTitle;

		if( $title->getNamespace() == NS_MODULE ) {
			$engine = Scripting::newDefaultEngine();
			$errors = $engine->validate( $text, $title->getPrefixedDBkey() );
			if( !$errors ) {
				return true;
			}

			$errmsg = wfMsgExt( 'scripting-error', array( 'parsemag' ), array( count( $errors ) ) );
			if( count( $errors ) == 1 ) {
				$errlines = ': ' . wfEscapeWikiText( $errors[0] );
			} else {
				$errlines = '* ' . implode( "\n* ", array_map( 'wfEscapeWikiText', $errors ) );
			}
			$error = <<<HTML
<div class="errorbox">
{$errmsg}
{$errlines}
</div>
<br clear="all" />
HTML;

			return true;
		}

		return true;
	}
}
