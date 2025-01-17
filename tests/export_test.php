<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Test scheduled export of wikis
 *
 * @package   local_wikiexport
 * @copyright 2023 Synergy Learning
 * @author    Davo Smith
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wikiexport;

class export_test extends \advanced_testcase {
    public function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_changed_wiki_exported(): void {
        set_config('publishemail', 'wiki@example.com', 'local_wikiexport');
        set_config('lastcron', '1', 'local_wikiexport'); // Otherwise nothing will be exported.

        $gen = self::getDataGenerator();
        /** @var \mod_wiki_generator $wgen */
        $wgen = $gen->get_plugin_generator('mod_wiki');

        self::setAdminUser();
        $c1 = $gen->create_course();
        $w1 = $wgen->create_instance(['course' => $c1->id, 'name' => 'Example wiki']);
        $wgen->create_first_page($w1, [
            'content' => '<p>The content of the first page - with info about [[Cats]], [[Dogs]] and [[Cows]]</p>',
        ]);
        $wgen->create_page($w1, ['title' => 'Cats', 'content' => 'The cat page']);
        $wgen->create_page($w1, ['title' => 'Dogs', 'content' => 'This is the dog page']);
        self::setUser();

        $sink = $this->redirectEmails();
        $task = new \local_wikiexport\task\email_wikis();
        $task->execute();

        $emails = $sink->get_messages();
        $email = array_shift($emails);

        $this->assertEquals('wiki@example.com', $email->to);
        $this->assertEquals('Wiki \'Example wiki\' updated', $email->subject);
        $this->assertStringContainsString('Updated export attached', $email->body);
        $this->assertMatchesRegularExpression('/filename=Export_Example_wiki_.*\.pdf/', $email->body);

        $this->assertEmpty($emails);
    }

    public function test_format_line_breaks_for_html(): void {
        global $USER;

        $gen = self::getDataGenerator();
        /** @var \mod_wiki_generator $wgen */
        $wgen = $gen->get_plugin_generator('mod_wiki');

        self::setAdminUser();
        $c1 = $gen->create_course();
        $w1 = $wgen->create_instance(['course' => $c1->id, 'name' => 'Example wiki']);
        self::setUser();
        $export = new \local_wikiexport\export($w1->cmid, $w1, 'epub', $USER, null);
    
        $reflectionMethod = new \ReflectionMethod(get_class($export), 'format_line_breaks_for_html');
        $reflectionMethod->setAccessible(true);
        $this->assertEquals(
            'The cat page ',
            $reflectionMethod->invokeArgs($export, ["The\r\ncat page\n"])
        );
        $this->assertEquals(
            "This is the <code>\ndog\n  inner 1\n/dog\n</code>page",
            $reflectionMethod->invokeArgs($export, ["This is the <code>\ndog\n  inner 1\n/dog\n</code>page"])
        );
        $this->assertEquals(
            "Some Code: <pre><code>\nimport os\n\nprint('foo bar')\n</code></pre> Fig. 1",
            $reflectionMethod->invokeArgs($export, ["Some Code:\n<pre><code>\nimport os\n\nprint('foo bar')\n</code></pre>\nFig. 1"])
        );
        $this->assertEquals(
            "Some Code: <pre><code data-lang=\"python\">\nimport os\n\nprint('foo bar')\n</code></pre> Fig. 1",
            $reflectionMethod->invokeArgs($export, ["Some Code: <pre><code data-lang=\"python\">\nimport os\n\nprint('foo bar')\n</code></pre> Fig. 1"])
        );
    }
}
