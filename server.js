const http = require('http');
const workerCode = require('./worker.js').default || require('./worker.js');

// Node polyfills
global.crypto = {
    subtle: {
        digest: async function(algorithm, data) {
            const crypto = require('crypto');
            return crypto.createHash('sha256').update(data).digest();
        }
    }
};

class ResponseNode {
    constructor(body, init) {
        this.body = body;
        this.status = init && init.status || 200;
        this.headers = init && init.headers || {};
    }
    async text() { return this.body; }
    async json() { return JSON.parse(this.body); }
}
global.Response = ResponseNode;

const server = http.createServer(async (req, res) => {
    let body = [];
    req.on('data', (chunk) => body.push(chunk));
    req.on('end', async () => {
        body = Buffer.concat(body).toString();
        
        const workerReq = {
            method: req.method,
            url: "http://localhost:3000" + req.url,
            json: async () => JSON.parse(body),
            text: async () => body
        };

        // Ensure global fetch is available
        if (typeof global.fetch === 'undefined') {
            global.fetch = globalThis.fetch;
        }

        try {
            const workerRes = await workerCode.fetch(workerReq);
            res.writeHead(workerRes.status, workerRes.headers);
            res.end(workerRes.body);
        } catch (e) {
            res.writeHead(500);
            res.end(String(e));
        }
    });
});
server.listen(3000, () => console.log('Listening on 3000'));
