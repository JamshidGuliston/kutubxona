$t = \App\Domain\Tenant\Models\Tenant::first();
echo "tenant: " . $t->slug . PHP_EOL;
echo "languages count: " . $t->languages->count() . PHP_EOL;
echo "default code: " . $t->default_locale . PHP_EOL;
