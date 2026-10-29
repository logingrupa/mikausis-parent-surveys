<?php

namespace Tests\Feature;

class ExampleTest extends SurveyTestCase
{
    public function test_public_homepage_lists_the_open_survey(): void
    {
        $this->get('/')->assertOk()->assertSee($this->survey->title);
    }
}
