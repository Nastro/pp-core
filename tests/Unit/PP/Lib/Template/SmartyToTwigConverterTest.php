<?php

namespace Tests\Unit\PP\Lib\Template;

use PP\Lib\Template\SmartyToTwig\Converter;
use PP\Lib\Template\SmartyToTwig\ConversionIssue;
use Tests\Base\AbstractUnitTest;

class SmartyToTwigConverterTest extends AbstractUnitTest
{
    /** @var Converter */
    private $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new Converter();
    }

    private function convert($smarty)
    {
        return $this->converter->convertSource($smarty)->twig;
    }

    public function testVariablesAndModifiers()
    {
        $this->assertEquals('{{ title }}', $this->convert('{$title}'));
        $this->assertEquals('{{ title|smarty_escape }}', $this->convert('{$title|escape}'));
        $this->assertEquals("{{ missing|smarty_default('n/a') }}", $this->convert("{\$missing|default:'n/a'}"));
        $this->assertEquals("{{ a|cat(', ')|cat(b) }}", $this->convert("{\$a|cat:', '|cat:\$b}"));
        $this->assertEquals("{{ text|smarty_replace('a', 'b') }}", $this->convert("{\$text|replace:'a':'b'}"));
        $this->assertEquals('{{ title|smarty_capitalize }}', $this->convert('{$title|capitalize}'));
        $this->assertEquals('{{ items|count }}', $this->convert('{$items|@count}'));
    }

    public function testObjectAndArrayAccess()
    {
        $this->assertEquals('{{ user.email }}', $this->convert('{$user.email}'));
        $this->assertEquals('{{ obj.getLabel() }}', $this->convert('{$obj->getLabel()}'));
        $this->assertEquals('{{ obj.value }}', $this->convert('{$obj->value}'));
        $this->assertEquals('{{ map[dynamicKey] }}', $this->convert('{$map.$dynamicKey}'));
        $this->assertEquals('{{ tree.getById(id).title }}', $this->convert('{$tree->getById($id)->title}'));
    }

    public function testDynamicKeyFollowedByPathContinuesOuterVariable()
    {
        // Smarty 2 `.$key` index is a plain word; `->prop`/`.key` after it
        // belongs to the outer path, not to the key expression.
        $this->assertEquals(
            '{{ tree.leafs[navId].content.show_menu_item }}',
            $this->convert('{$tree->leafs.$navId->content.show_menu_item}')
        );
        $this->assertEquals('{{ map[k][j] }}', $this->convert('{$map.$k.$j}'));
    }

    public function testBackslashesInStringsSurviveTwigLexer()
    {
        // twig lexer applies stripcslashes() to literals: a smarty regex
        // '/\?.*$/' must be re-escaped or twig turns it into '/?.*$/'
        $this->assertEquals(
            "{{ url|regex_replace('/\\\\?.*\$/', '') }}",
            $this->convert('{$url|regex_replace:\'/\\?.*$/\':\'\'}')
        );
        $this->assertEquals("{{ 'a\\\\'|cat('b') }}", $this->convert("{'a\\\\'|cat:'b'}"));
    }

    public function testStrictComparisonBecomesSameAsTest()
    {
        $result = $this->converter->convertSource("{if \$tariff.internet === '1'}Гб{/if}");
        $this->assertTrue($result->isClean());
        $this->assertEquals("{% if tariff.internet is same as('1') %}Гб{% endif %}", $result->twig);

        $this->assertEquals(
            '{% if a is not same as(true) and b is same as(10) %}x{% endif %}',
            $this->convert('{if $a !== true && $b === 10}x{/if}')
        );
    }

    public function testSectionWithExplicitStepOneIsClean()
    {
        $result = $this->converter->convertSource(
            '{section name=p start=1 loop=$total+1 step=1}{$smarty.section.p.index}{/section}'
        );
        $this->assertTrue($result->isClean());
        $this->assertStringContainsString('{% for __sec_p in', $result->twig);
    }

    public function testConditions()
    {
        $this->assertEquals(
            '{% if user.age >= 18 and not (user.email)|empty %}x{% endif %}',
            $this->convert('{if $user.age gte 18 && !empty($user.email)}x{/if}')
        );
        $this->assertEquals(
            '{% if a == "y" %}1{% elseif b != 2 %}2{% else %}3{% endif %}',
            $this->convert('{if $a eq "y"}1{elseif $b ne 2}2{else}3{/if}')
        );
        $this->assertEquals(
            '{% if (title)|isset %}x{% endif %}',
            $this->convert('{if isset($title)}x{/if}')
        );
    }

    public function testForeach()
    {
        $this->assertEquals(
            '{% for k, item in (items) ?: [] %}{{ item }}{% else %}none{% endfor %}',
            $this->convert('{foreach from=$items item=item key=k}{$item}{foreachelse}none{/foreach}')
        );
        $this->assertEquals(
            '{% for x in (list) ?: [] %}{{ loop.index }}/{{ loop.length }}{% endfor %}',
            $this->convert('{foreach from=$list item=x name=n}{$smarty.foreach.n.iteration}/{$smarty.foreach.n.total}{/foreach}')
        );
    }

    public function testNestedForeachParentLoop()
    {
        $twig = $this->convert(
            '{foreach from=$a item=x name=outer}{foreach from=$x item=y name=inner}{$smarty.foreach.outer.iteration}{/foreach}{/foreach}'
        );

        $this->assertStringContainsString('loop.parent.loop.index', $twig);
    }

    public function testAssignCaptureMath()
    {
        $this->assertEquals("{% set cls = 'red' %}", $this->convert("{assign var=cls value='red'}"));
        $this->assertEquals(
            '{% set __capture_t %}X{% endset %}{{ __capture_t }}',
            $this->convert('{capture name=t}X{/capture}{$smarty.capture.t}')
        );
        $this->assertEquals(
            '{% set r = (n) * (m) + 2 %}',
            $this->convert("{math equation='x * y + 2' x=\$n y=\$m assign=r}")
        );
    }

    public function testInclude()
    {
        $this->assertEquals(
            "{% include 'sub/part.twig' %}",
            $this->convert("{include file='sub/part.tmpl'}")
        );
        $this->assertEquals(
            "{% include 'sub/part.twig' with {extra: 'passed'} %}",
            $this->convert("{include file='sub/part.tmpl' extra='passed'}")
        );
        $this->assertEquals(
            '{% include "dt/#{contentType}.twig" %}',
            $this->convert('{include file="dt/$contentType.tmpl"}')
        );
    }

    public function testStrip()
    {
        $this->assertEquals(
            '<a>x</a><b>y</b>',
            $this->convert("{strip}\n\t<a>x</a>\n\t<b>y</b>\n{/strip}")
        );
    }

    public function testLiteralAndComment()
    {
        $this->assertEquals(
            "<script>var a = {};</script>",
            $this->convert("{literal}<script>var a = {};</script>{/literal}")
        );
        $this->assertEquals('{# note #}', $this->convert('{* note *}'));
    }

    public function testSmartyRequestVars()
    {
        $this->assertEquals('{{ smarty.get.page }}', $this->convert('{$smarty.get.page}'));
        $this->assertEquals("{{ constant('DEFAULT_CHARSET') }}", $this->convert('{$smarty.const.DEFAULT_CHARSET}'));
    }

    public function testPagerEmitsVarResync()
    {
        $twig = $this->convert('{pager objects=$objects format=$ctype notshow=true}');

        $this->assertStringContainsString('{{ pager({objects: objects, format: ctype, notshow: true}) }}', $twig);
        $this->assertStringContainsString("layout_var('a_per_page')", $twig);
    }

    public function testSectionBecomesRangeLoop()
    {
        $twig = $this->convert('{section name=i start=1 loop=$max}{$smarty.section.i.index}{/section}');

        $this->assertStringContainsString('range(1, __sec_i_total - 1)', $twig);
        $this->assertStringContainsString('{{ __sec_i }}', $twig);
    }

    public function testPhpBlockReportsError()
    {
        $result = $this->converter->convertSource('{php}echo 1;{/php}');

        $this->assertNotEmpty($result->getIssues(ConversionIssue::ERROR));
        $this->assertStringContainsString('UNCONVERTED', $result->twig);
    }

    public function testBreakReportsError()
    {
        $result = $this->converter->convertSource('{foreach from=$a item=x}{break}{/foreach}');

        $this->assertFalse($result->isClean());
    }

    public function testUnknownModifierWarns()
    {
        // project modifiers registered at web runtime are not visible to
        // the CLI converter — the file must stay convertible (no ERROR)
        $result = $this->converter->convertSource('{$x|definitely_not_a_function_9000}');

        $this->assertNotEmpty($result->getIssues(ConversionIssue::WARNING));
        $this->assertTrue($result->isClean());
        $this->assertEquals('{{ x|definitely_not_a_function_9000 }}', $result->twig);
    }

    public function testAssignInsideLoopWarns()
    {
        $result = $this->converter->convertSource('{foreach from=$a item=x}{assign var=y value=$x}{/foreach}');

        $this->assertNotEmpty($result->getIssues(ConversionIssue::WARNING));
    }

    public function testTrailingTemplateNewlineIsDropped()
    {
        $this->assertEquals('A', $this->convert("A\n"));
    }

    public function testNewlineAfterOutputTagIsKept()
    {
        $this->assertEquals("{{ v }}\nB", $this->convert("{\$v}\nB"));
    }
}
