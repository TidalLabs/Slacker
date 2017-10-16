
const blessed = require('blessed');
const wrap = require('word-wrap');
const moment = require('moment');

export default class MessagesList {

    constructor(channel) {
        this.channel = channel;
        this.screen = this.channel.screen;
        this.api = this.channel.api;
        this.exists = true;

        this.box = blessed.box({
            parent: this.channel.box,
            top: 'top',
            left: '0%',
            width: '100%-2',
            height: '90%',
            tags: true,
            scrollable: true,
            alwaysScroll: true,
            input: true,
            mouse: true,
            vi: true,
            keys: true,
            scrollbar: {
                ch: " ",
                inverse: true
            },
            style: {
                fg: 'white',
                bg: 'black',
                border: {
                    fg: '#f0f0f0'
                }
            }
        });

        this.messages = [];

        this.init();

        this.loadHistory = this.loadHistory.bind(this);
    }

    getUserReplacementMap() {
        let map = {};
        Object.values(this.api.users).forEach(u => {
            map['@' + u.id] = '@' + u.name;
        });
        return map;
    }

    init() {
        this.box.enableInput();
        this.screen.render();
        this.refresh();
        this.api.on('message', this.receiveMessage.bind(this));
    }

    receiveMessage(obj) {
        if (!this.exists) return null;
        if (obj.channel === this.channel.channel.id) {
            this.messages.push(obj);
            this.render();
        }
    }

    refresh() {
        this.api.fetchChannelHistory(this.channel.channel, history => {this.loadHistory(history)});
    }

    destroy() {
        this.exists = false;
        this.box.destroy();
    }

    render() {
        // prevent against
        if (!this.box) return null;
        let lines = [];
        const width = parseInt(this.box.width) - 15;
        const userMap = this.getUserReplacementMap();
        this.messages
                .filter(m => m.type === 'message')
                .forEach(m => {
                    const userName = (typeof m.user !== 'undefined')
                        ? this.api.getUserName(m.user)
                        : (m.username ? m.username : 'Unknown User')
                    ;
                    let time = moment.unix(m.ts);
                    let formattedTime = time.format('h:mma')
                    let content = '{bold}'+userName + '{/bold} '
                        + '{grey-fg}'+formattedTime+'{/grey-fg}: '
                        + (m.text ? m.text : JSON.stringify(m));
                    for (const replaceId in userMap) {
                        const replaceName = userMap[replaceId];
                        content = content.replace(replaceId, replaceName);
                    }
                    const wrapped = wrap(content, {width}) + "\n";
                    const exploded = wrapped.split("\n");
                    lines = lines.concat(exploded);
                });

        this.box.setContent(lines.join("\n") + "\n");
        this.box.setScrollPerc(100);
        this.screen.render();
    }

    loadHistory(body) {
        if (body.ok) {
            this.messages = body.messages.slice(0).reverse();
            this.screen.log("MessagesList: Attempt to mark channel " + this.channel.channel.id + " read");
            this.api.markChannelRead(this.channel.channel);
        } else {
            this.messages = [{
                text: 'Trouble loading this room. Error message was: ' + body.error + '. Try again later.',
                username: 'Slacker App',
                type: 'message',
                ts: (Date.now() / 1000)
            }];
        }
        this.render();
    }
}

module.exports = MessagesList;