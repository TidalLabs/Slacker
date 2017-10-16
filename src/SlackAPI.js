
const EventEmitter = require('events').EventEmitter;
const request = require('request');
const WebSocketClient = require('websocket').client;


export const SLACK_API = 'https://slack.com/api/';

export default class SlackAPI extends EventEmitter {
    constructor(token, screen) {
        super();

        this.screen = screen; // used only for logging here
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

                    if (this.channels[obj.channel]) {
                        if (typeof this.channels[obj.channel].history === 'undefined') {
                            this.channels[obj.channel].history = {messages: []};
                        }
                        if (typeof this.channels[obj.channel].history.messages === 'undefined') {
                            this.channels[obj.channel].history.messages = [];
                        }
                        this.channels[obj.channel].history.messages.unshift(obj);
                        this.screen.log("API: Added message to channel history, now " + this.channels[obj.channel].history.messages.length + " messages in " + obj.channel);
                    } else {
                        this.screen.log("API: couldn't add message to channel history, channel does not exist " + obj.channel);
                        this.screen.log(obj);
                    }
                });

            });


            this.rtm.on('connectFailed', function(error) {
                console.log('Connect Error: ' + error.toString());
            });


            this.rtm.connect(body.url);

        });

    }

    getChannelDisplayName(channel) {
        let display_name = channel.name || '';

        if (channel.is_im) {
            display_name = '@' + this.getUserName(channel.user);
        } else if (channel.is_mpim) {
            display_name = '@' + display_name
                .replace('mpdm-', '')
                .replace('-1', '')
                .replace(/--/g, ', ');
        } else if (channel.is_channel || channel.is_private) {
            display_name = '#' + display_name;
        }

        return display_name;

    }

    markChannelRead(channel, callback) {
        let endpoint = 'channels.mark';
        if (channel.is_im) endpoint = 'im.mark';
        else if (channel.is_private) endpoint = 'groups.mark';
        else if (channel.is_mpim) endpoint = 'mpim.mark';

        if (this.channels[channel.id] && this.channels[channel.id].history && this.channels[channel.id].history.messages) {
            let mostRecentMessages = this.channels[channel.id].history.messages.filter(m => typeof m.ts !== 'undefined').sort((a, b) => {
                let ats = 0;
                let bts = 0;
                if (a.ts) ats = parseFloat(a.ts);
                if (b.ts) bts = parseFloat(b.ts);
                return ats < bts ? 1 : -1;
            });

            let mostRecentMessage = mostRecentMessages[0];
            const payload = {channel: channel.id, ts: mostRecentMessage.ts};

            this.screen.log("API: Marking channel as read");
            this.screen.log(mostRecentMessage);
            this.screen.log(JSON.stringify(payload));

            this.post(endpoint, payload, (err, resp, body) => {
                this.screen.log("API: Marking channel as read got response");
                this.screen.log(JSON.stringify(body));
                if (typeof callback === 'function') callback(body);
            });
        } else {
            this.screen.log("API: Couldn't mark channel " + channel.id + " as read");
            this.screen.log(JSON.stringify(this.channels[channel.id]));
            this.screen.log(JSON.stringify(Object.keys(this.channels)));
        }


    }

    fetchChannelHistory(channel, callback) {
        this.screen.log("API: Fetching channel history for " + channel.id);
        return this.get(
            'conversations.history',
            {channel: channel.id},
            (err, resp, body) => {
                if (err) {
                    this.screen.log("Error fetching history");
                    this.screen.log(err);
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
