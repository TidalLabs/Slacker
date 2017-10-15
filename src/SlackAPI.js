
var request = require('request');
var WebSocketClient = require('websocket').client;

export const SLACK_API = 'https://slack.com/api/';

export default class SlackAPI {
    constructor(token) {
        this.token = token;
        this.users = {};
        this.messages = [];
        this.rtm = null;

        this.callbacks = {
            onReceiveMessage: null
        };

        this.init();
    }

    init() {
        this.initUsers();
        this.connectRTM();
    }

    onReceiveMessage(callback) {
        this.callbacks.onReceiveMessage = callback;
    }

    connectRTM() {
        this.rtm = new WebSocketClient();

        this.get('rtm.connect', {}, (err, resp, body) => {

            this.rtm.on('connect', (connection) => {

                connection.on('error', function(error) {
                    console.log("Connection Error: " + error.toString());
                });

                connection.on('message', (message) => {
                    const data = message.utf8Data;
                    const obj = JSON.parse(data);
                    this.messages.push(obj);
                    if (this.callbacks.onReceiveMessage) {
                        this.callbacks.onReceiveMessage(obj);
                    }
                });

            });


            this.rtm.on('connectFailed', function(error) {
                console.log('Connect Error: ' + error.toString());
            });


            this.rtm.connect(body.url);

        });

    }

    getChannels(callback) {
        return this.get('conversations.list',
            {exclude_archived: true, types: 'public_channel,private_channel,mpim,im', limit: 500},
            callback);
    }

    getChannelHistory(channel, callback) {
        return this.get('conversations.history', {channel: channel.id}, callback);
    }

    getUsers(callback) {
        return this.get('users.list', {}, callback);
    }

    getUser(id) {
        return this.users[id] || {name: 'Unknown User'};
    }

    getUserName(id) {
        return this.getUser(id).name;
    }

    postMessage(channel, text, callback) {
        this.post('chat.postMessage', {channel: channel.id, text, as_user: true}, callback);
    }

    initUsers() {
        this.getUsers((err, resp, body) => {
            let out = {};
            body.members.forEach(member => {
                out[member.id] = member;
            });
            this.users = out;
        });
    }

    get(methodName, args = null, callback) {
        const url = SLACK_API+methodName;

        if (args === null) {
            args = {};
        }

        args['token'] = this.token;

        return request({
            method: 'GET',
            url,
            json: true,
            qs: args
        }, callback);

    }

    post(methodName, args = null, callback) {

        const url = SLACK_API+methodName;
        if (args === null) {
            args = {};
        }

        args['token'] = this.token;

        return request({
            method: 'POST',
            url,
            form: args
        }, callback);
    }

}
module.exports = SlackAPI;
