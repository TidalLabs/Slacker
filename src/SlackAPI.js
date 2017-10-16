
const EventEmitter = require('events').EventEmitter;
const request = require('request');
const WebSocketClient = require('websocket').client;


export const SLACK_API = 'https://slack.com/api/';

export default class SlackAPI extends EventEmitter {
    constructor(token) {
        super();

        this.token = token;
        this.users = {};
        this.channels = {};
        this.messages = [];
        this.rtm = null;

        this.init();
    }

    init() {
        this.fetchUsers();
        this.connectRTM();
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
                    this.emit('message', obj);
                });

            });


            this.rtm.on('connectFailed', function(error) {
                console.log('Connect Error: ' + error.toString());
            });


            this.rtm.connect(body.url);

        });

    }

    fetchChannelHistory(channel, callback) {
        return this.get(
            'conversations.history',
            {channel: channel.id},
            (err, resp, body) => {
                if (err) {
                    console.log("Error fetching history");
                    console.log(err);
                }
                this.channels[channel.id].history = body;
                if (typeof callback === 'function') callback(body);
            }
        );
    }

    getUser(id) {
        return this.users[id] || {id, name: id};
    }

    getUserName(id) {
        return this.getUser(id).name;
    }

    postMessage(channel, text, callback) {
        this.post('chat.postMessage', {channel: channel.id, text, as_user: true}, callback);
    }

    fetchChannels(callback) {
        return this.get(
            'conversations.list',
            {exclude_archived: true, types: 'public_channel,private_channel,mpim,im', limit: 500},
            (err, resp, body) => {

                let out = {};
                for (const channel of body.channels) {
                    out[channel.id] = channel;
                }
                this.channels = out;

                if (typeof callback === 'function') callback(out);
            }
        );
    }

    fetchUsers(callback) {
        return this.get('users.list', {}, (err, resp, body) => {
            let out = {};
            body.members.forEach(member => {
                out[member.id] = member;
            });
            this.users = out;
            if (typeof callback === 'function') callback(out);
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
