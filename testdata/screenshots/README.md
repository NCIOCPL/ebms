# Test Screenshots

This directory is used to hold screenshots created during tests run with
`phpunit`. Such screenshots can be helpful in figuring out what is displayed
on the web page at a given moment of the tests. An example of the code to
create a screenshot:

```php
    $url = Url::fromRoute('ebms_review.packet_form')->toString();
    $this->drupalGet($url);
    $this->createScreenshot('../testdata/screenshots/create-packet-page.png');
```
