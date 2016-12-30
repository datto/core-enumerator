<?php

/*
 * Enumerator: class for crawling a PHP namespace and listing classes underneath it
 * Copyright (C) 2014 Datto Inc.
 * Written by Dan Fuhry <dfuhry@dattobackup.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace Datto\Core;
use Composer\Autoload\ClassLoader;
use DirectoryIterator;

/**
 * Enumerator for namespaces. This is designed to make sub-component auto-discovery easier.
 * @author Dan Fuhry <dfuhry@dattobackup.com>
 */

class Enumerator
{
	/**
	 * The base directory for the project.
	 * @var string
	 * @static
	 */

	private static $root = null;

	/**
	 * Return a list of classes under a given namespace. It is an error to enumerate the root namespace.
	 * @param string Namespace name. Trailing backslash may be omitted.
	 * @param Limiting rules. An array of constraints on the returned results. Currently
	 *  the only supported constraints are "implements" (string - class name) and "abstract"
	 *  (boolean).
	 *  Use it: [ ['implements' => 'Some\Interface', 'abstract' => false] ]
	 * @return array
	 * @static
	 */
	
	public static function getClasses($namespace, $conditions = [])
	{
		self::_setupRoot();

		$namespace = explode('\\', trim($namespace, '\\'));
		
		$composerNamespaces = self::getComposerNamespaces();
		$searchPaths = [];
		
		// for each Composer namespace, assess whether it may contain the namespace $namespace.
		foreach ( $composerNamespaces as $ns => $paths )
		{
			$nsTrimmed = explode('\\', trim($ns, '\\'));
			for ( $i = min(count($nsTrimmed), count($namespace)); $i > 0; $i-- ) {
				if ( array_slice($nsTrimmed, 0, $i) === array_slice($namespace, 0, $i) ) {
					$searchPaths[] = [
							'prefix' => $nsTrimmed
							, 'paths' => $paths
						];
					
					continue;
				}
			}
		}
		
		// $searchPaths now contains a list of PSR-4 namespaces we must search for
		// classes under the namespace $namespace.
		
		// FIXME crawling everything under the sun is potentially very time-consuming. It would make a lot of
		// sense to be able to generate a cache classes specifying what interfaces each class implements, etc.
		
		$result = [];
		foreach ( $searchPaths as $pathspec ) {
			foreach ( $pathspec['paths'] as $path ) {
				// Determine the path under which the namespace we are searching for will exist for this pathspace
				$path = $path
						. DIRECTORY_SEPARATOR
						. implode(DIRECTORY_SEPARATOR, array_slice($namespace, count($pathspec['prefix'])));
						
				$prefix = count($pathspec['prefix']) > count($namespace) ? implode('\\', $pathspec['prefix']) : implode('\\', $namespace);
						
				// if that path exists, go ahead and search it for class files and append them to the result.
				if ( is_dir($path) ) {
					$result = array_merge($result, self::searchPath($prefix, rtrim($path, DIRECTORY_SEPARATOR), $conditions));
				}
			}
		}
		
		return $result;
	}
	
	/**
	 * Return the name of the composer module where a given class lives.
	 * @param string Class name
	 * @return mixed String if it lives in a module, or null if the root
	 */
	
	public static function getComposerModule($className)
	{
		global $Composer;
		
		if ( $result = $Composer->findFile($className) ) {
			$result = substr($result, strlen(self::$root));
			
			if ( preg_match('#^vendor/([a-z0-9_-]+/[a-z0-9_-]+)/#', $result, $match) ) {
				return $match[1];
			}
		}
		
		return null;
	}
	
	/**
	 * Get Composer's namespace list.
	 * @return array
	 * @access private
	 * @static
	 */
	
	private static function getComposerNamespaces()
	{
		static $result = null;
		
		if ( !is_array($result) ) {
			$result = require self::$root . 'vendor/composer/autoload_namespaces.php';
			
			foreach ( $result as $ns => &$paths ) {
				$subpath = trim(str_replace('\\', DIRECTORY_SEPARATOR, $ns), DIRECTORY_SEPARATOR);
				foreach ( $paths as &$path ) {
					$path = rtrim($path, DIRECTORY_SEPARATOR);
					if ( is_dir("$path/$subpath") ) {
						$path = "$path/$subpath";
					}
				}
				unset($path);
			}
			unset($paths);
			
			$psr4 = require self::$root . 'vendor/composer/autoload_psr4.php';
			
			foreach ( $psr4 as $ns => $paths )
			{
				if ( isset($result[$ns]) ) {
					$result[$ns] = array_merge($result[$ns], $paths);
				}
				else {
					$result[$ns] = $paths;
				}
			}
		}
		
		return $result;
	}
	
	/**
	 * Search one directory recursively for classes belonging to a given namespace.
	 * @param string Namespace to search for
	 * @param string Directory to search
	 * @param array Search conditions
	 * @return array
	 * @access private
	 * @static
	 */
	
	private static function searchPath($prefix, $path, $conditions)
	{
		$result = [];
		
		$dir = new DirectoryIterator($path);
		foreach ( $dir as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}
			
			$de = $entry->getFilename();
			
			if ( $entry->isDir() && preg_match('/^[A-Za-z_]+$/', $de) ) {
				// it's a subdirectory, search it
				$result = array_merge($result, self::searchPath("$prefix\\$de", $path . DIRECTORY_SEPARATOR . $de, $conditions));
			}
			else if ( preg_match('/\.php$/', $de) ) {
				// FIXME I'm using a regex test here because that in theory is quicker than having
				// PHP parse the file. Also, this is deliberately designed to exclude abstract classes,
				// which will cause class_exists() to return (bool)true.
				
				$foundNamespace = $foundClass = false;
				$className = preg_replace('/\.php$/', '', $de);
				
				if ( $fp = @fopen($filePath = "$path/$de", 'r') ) {
					
					while ( !feof($fp) && ($line = fgets($fp)) ) {
						// FIXME PHP leaves pretty little room for error in the namespace declaration
						// line. This is still a little needlessly picky - if someone has a space between
						// the namespace name and the semicolon this test will fail, as well as dumber
						// stuff like the word "namespace" being mixed case/uppercase.
						if ( trim($line) === "namespace $prefix;" ) {
							$foundNamespace = true;
						}
						// This fairly complicated regexp covers "implements" with multiple inheritance,
						// "extends" and a fair amount of syntactical deviation.
						else if ( preg_match('/^\s*class ([A-Za-z_]+)(?:\s+(?:extends|implements) [A-Za-z_\\\\]+(?:\s*,\s*[A-Za-z_]+)*)*\s*[\{,]?$/', trim($line), $match)
								&& ($match[1] === "\\$prefix\\$className" || $match[1] === $className) ) {
							$foundClass = true;
						}
						
						// if we have found the namespace declaration and the class declaration,
						// we no longer need any more information from this file, so break and
						// close the handle.
						if ( $foundNamespace && $foundClass ) {
							break;
						}
					}
					
					fclose($fp);
				}
				
				// if we found the namespace declaration and the class declaration, append
				// the discovered class to the result list.
				if ( $foundNamespace && $foundClass )
				{
					// fully qualified class name
					$fqcn = "$prefix\\$className";
					
					// conditions are an OR between each, AND within an individual condition
					$condCount = 0;
					
					// ReflectionClass instance - created only if necessary
					$rfl = null;
					foreach ( $conditions as $cond ) {
						$condResult = true;
						foreach ( $cond as $test => $value ) {
							switch($test) {
							case 'implements':
								if ( empty($rfl) ) {
									$rfl = new \ReflectionClass($fqcn);
								}
								if ( !$rfl->implementsInterface($value) ) {
									$condResult = false;
									break 2; // lazy evaluation
								}
								break;
							case 'extends':
								if ( empty($rfl) ) {
									$rfl = new \ReflectionClass($fqcn);
								}
								if ( $rfl->getParentClass()->getName() !== $value ) {
									$condResult = false;
									break 2; // lazy evaluation
								}
								break;
							case 'abstract':
								if ( empty($rfl) ) {
									$rfl = new \ReflectionClass($fqcn);
								}
								if ( $rfl->isAbstract() !== $value ) {
									$condResult = false;
									break 2; // lazy evaluation
								}
								break;
							}
						}
						if ( $condResult ) {
							$condCount++;
						}
					}
					
					if ( $condCount || count($conditions) === 0 ) {
						$result[] = $fqcn;
					}
				}
			}
		}
		
		return $result;
	}
	/**
	 * Determine the project root directory.
	 */

	private static function _setupRoot()
	{
		if ( !empty(self::$root) ) {
			// avoid duplicating efforts... only set root if it's not already set 
			return;
		}
		
		if ( defined('ROOT') ) {
			self::$root = rtrim(ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		}
		else if ( !empty($GLOBALS['baseDir']) ) {
			self::$root = rtrim($GLOBALS['baseDir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		}
		else if ( strpos(__FILE__, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false ) {
			// fall back to guessing the project root from the path...
			// we're in /vendor/datto/core-enumerator/src/Core

			$path = __FILE__;
			// up 5 levels
			while (!file_exists("$path/autoload.php")) {
				$path = dirname($path);
			}
			
			$path = dirname($path);

			self::$root = $path . DIRECTORY_SEPARATOR;
		}
		else if ( class_exists("Composer\\Autoload\\ClassLoader") ) {
			// go up until we find vendor/autoload.php - last resort
			$path = dirname(__FILE__);
			while ( $path && !file_exists($path . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php') ) {
				$path = dirname($path);
			}

			if ( !empty($path) ) {
				var_dump($path);
				self::$root = $path . DIRECTORY_SEPARATOR;
			}
		}

		if ( empty(self::$root) ) {
			throw new \Exception("Unable to determine project base directory");
		}
	}
}

