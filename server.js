const http = require('http');
const workerCode = require('./worker.js').default || require('./worker.js');

const server = http.createServer(async (req, res) => {
    let body = [];
    req.on('data', (chunk) => body.push(chunk));
    req.on('end', async () => {
        body = Buffer.concat(body).toString();
        
        const fullUrl = `http://${req.headers.host || 'localhost:3000'}${req.url}`;
        const options = {
            method: req.method,
            headers: req.headers,
        };
        if (req.method !== 'GET' && req.method !== 'HEAD' && body) {
            options.body = body;
        }

        const workerReq = new Request(fullUrl, options);

        try {
            const workerRes = await workerCode.fetch(workerReq);
            const headers = {};
            workerRes.headers.forEach((value, key) => {
                headers[key] = value;
            });
            res.writeHead(workerRes.status, headers);
            const responseText = await workerRes.text();
            res.end(responseText);
        } catch (e) {
            res.writeHead(500, { 'Content-Type': 'text/plain' });
            res.end(String(e && e.stack ? e.stack : e));
        }
    });
});

server.listen(3000, () => console.log('Listening on 3000'));
