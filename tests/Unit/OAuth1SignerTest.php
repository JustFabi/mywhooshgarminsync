use App\Services\Garmin\OAuth1Signer;
use App\Services\Garmin\GarminException;

it('produces an RFC 5849 HMAC-SHA1 signature (cross-verified against node:crypto)', function () {
    $signer = new OAuth1Signer;

    $url = 'https://connectapi.garmin.com/oauth-service/oauth/preauthorized'
        .'?ticket=ST-1234'
        .'&login-url='.rawurlencode('https://sso.garmin.com/sso/embed')
        .'&accepts-mfa-tokens=true';

    $header = $signer->header(
        'GET',
        $url,
        'CK',
        'CS',
        token: '',
        tokenSecret: '',
        nonce: 'n0nce1234',
        timestamp: '1700000000',
    );

    expect($header)->toBe(
        'OAuth oauth_consumer_key="CK", oauth_nonce="n0nce1234", '
        .'oauth_signature="cx1xof6FPOGA4l7DngzbI5eilDQ%3D", '
        .'oauth_signature_method="HMAC-SHA1", oauth_timestamp="1700000000", '
        .'oauth_token="", oauth_version="1.0"'
    );
});

it('computes a deterministic signature including token secret in the key', function () {
    $signer = new OAuth1Signer;

    $fixedParams = [
        'oauth_consumer_key' => 'CK',
        'oauth_nonce' => 'n',
        'oauth_timestamp' => '1',
        'oauth_token' => 'tok',
        'oauth_version' => '1.0',
        'oauth_signature_method' => 'HMAC-SHA1',
    ];

    $withoutTokenSecret = $signer->signature('POST', 'https://x/y', $fixedParams, 'S', '');
    $withTokenSecret = $signer->signature('POST', 'https://x/y', $fixedParams, 'S', 'T');

    expect($withoutTokenSecret)->not->toBe($withTokenSecret);
});

it('rejects malformed URLs', function () {
    (new OAuth1Signer)->header('GET', 'not a url', 'k', 's');
})->throws(GarminException::class);
