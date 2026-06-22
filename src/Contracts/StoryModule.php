<?php
namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Contracts\StoryPayload;

interface StoryModule
{
    public function run(): StoryPayload;
    public function getLabel(): string;
}