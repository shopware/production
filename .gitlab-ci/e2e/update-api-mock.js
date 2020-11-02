const crypto = require('crypto')
const fs = require('fs');
const path = require('path');

const express = require('express')
const app = express()

function sign(data, privateKey) {
    const sign = crypto.createSign('sha1');
    sign.write(data);
    sign.end();

    return sign.sign(privateKey, 'base64');
}

app.get('/update.zip', (req, res) => {
    res.sendFile(UPDATE_FILE_PATH)
})

app.post('/swplatform/autoupdate', (req, res) => {
    const body = JSON.stringify([]);
    res.set('x-shopware-signature', sign(body, privateKey));
    res.send(body);
})

app.get('/v1/release/update', (req, res) => {
    let body = JSON.stringify({
        version: FAKE_VERSION,
        release_date: null,
        security_update: false,
        uri: baseUrl + '/update.zip',
        size: updateSize,
        sha1: updateSha1,
        sha256: updateSha256,
        checks: [
            {
                type: 'writable',
                value: [
                    '/'
                ],
                level: 10,
            },
            {
                type: 'phpversion',
                value: '7.4.0',
                level: 20,
            },
            {
                type: 'mysqlversion',
                value: '5.7',
                level: 20,
            },
            {
                type: 'licensecheck',
                value: {},
                level: 20,
            },
        ],
        changelog: {
            de: {
                id: '240',
                releaseId: null,
                language: 'de',
                changelog: "<h2>Shopware Version test</h2>\n\nTestVersion\n",
                release_id: '126',
            },
            en: {
                id: '241',
                releaseId: null,
                language: 'en',
                changelog: "<h2>Shopware Version test</h2>\n\nTestVersion\n",
                release_id: '126',
            },
        },
        isNewer: true
    });

    res.set('x-shopware-signature', sign(body, privateKey));
    res.send(body);
})


let { PORT, FAKE_VERSION, TAG, UPDATE_FILE_PATH, WEB_DOCUMENT_ROOT } = process.env;

FAKE_VERSION = FAKE_VERSION || TAG || '6.2.1';
FAKE_VERSION = FAKE_VERSION[0] === 'v' ? FAKE_VERSION.slice(1) : FAKE_VERSION;

PORT = PORT || 3000
UPDATE_FILE_PATH = UPDATE_FILE_PATH || path.join(__dirname, 'data/update.zip');

const privateKeyFilePath = path.join(__dirname, 'data/private');

let publicKeyFilePath = path.join(WEB_DOCUMENT_ROOT, '../vendor/shopware/platform/src/Core/Framework/Store/public.key');
if (!fs.existsSync(path.join(WEB_DOCUMENT_ROOT, '../vendor/shopware/platform'))) {
     publicKeyFilePath = path.join(WEB_DOCUMENT_ROOT, '../vendor/shopware/core/Framework/Store/public.key');
}

const encoded = crypto.generateKeyPairSync('rsa', {
    modulusLength: 2048,
    publicKeyEncoding: {
        type: 'spki',
        format: 'pem'
    },
    privateKeyEncoding: {
        type: 'pkcs8',
        format: 'pem',
    }
});

fs.writeFileSync(publicKeyFilePath, encoded.publicKey);
fs.writeFileSync(privateKeyFilePath, encoded.privateKey);

let privateKey = crypto.createPrivateKey(encoded.privateKey)

const baseUrl = 'http://localhost:' + PORT;

const updateSize = fs.statSync(UPDATE_FILE_PATH).size;

const updateReadStream = fs.createReadStream(UPDATE_FILE_PATH);

const updateSha1Hasher = crypto.createHash('sha1');
const updateSha256Hasher = crypto.createHash('sha256');

let updateSha1;
let updateSha256;

updateReadStream.on('open', function () {
    updateReadStream.pipe(updateSha1Hasher);
    updateReadStream.pipe(updateSha256Hasher)
});

updateReadStream.on('end', () => {
    updateSha1Hasher.end();
    updateSha1 = updateSha1Hasher.digest('hex');

    updateSha256Hasher.end();
    updateSha256 = updateSha256Hasher.digest('hex');

    app.listen(PORT, () => console.log(`Example app listening at http://localhost:${PORT}`))
})
