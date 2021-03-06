<?php
/**
 * This file is part of the Passwords App
 * created by Marius David Wieschollek
 * and licensed under the AGPL.
 */

namespace OCA\Passwords\Helper\Words;

/**
 * Class RandomCharactersHelper
 *
 * @package OCA\Passwords\Helper\Words
 */
class RandomCharactersHelper extends AbstractWordsHelper {

    const CHARACTER_LIST = 'AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz';

    /**
     * @param int $strength
     *
     * @return array
     */
    public function getWords(int $strength): array {
        $strength = $strength == 1 ? 2:$strength;
        $length   = $strength * 3;
        $words    = [];

        for($i = 0; $i < $strength; $i++) {
            $string  = str_shuffle(self::CHARACTER_LIST);
            $start   = random_int(0, strlen($string) - $length);
            $words[] = substr($string, $start, $length);
        }

        return $words;
    }
}