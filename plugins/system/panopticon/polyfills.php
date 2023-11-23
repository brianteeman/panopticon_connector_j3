<?php
/**
 * Polyfills for PHP 8 features on PHP 7 servers.
 *
 * This code is adapted from the Symfony Polyfill component
 * https://github.com/symfony/polyfill
 *
 * The original code comes with the following copyright notice:
 *
 * -----------------------------------------------------------------------------
 * Copyright (c) 2015-present Fabien Potencier
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * -----------------------------------------------------------------------------
 */
if (!function_exists('str_contains'))
{
	function str_contains(string $haystack, string $needle): bool
	{
		return $needle === '' || strpos($haystack, $needle) !== false;
	}
}

if (!function_exists('str_starts_with'))
{
	function str_starts_with(string $haystack, string $needle): bool
	{
		return strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}

if (!function_exists('str_ends_with'))
{
	function str_ends_with(string $haystack, string $needle): bool
	{
		if ($needle === '' || $haystack === $needle)
		{
			return true;
		}

		if ($haystack === '')
		{
			return false;
		}

		$needleLength = strlen($needle);

		return $needleLength <= \strlen($haystack) && substr_compare($haystack, $needle, -$needleLength) === 0;
	}
}