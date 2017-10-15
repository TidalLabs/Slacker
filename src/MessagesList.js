
var blessed = require('blessed');
var wrap = require('word-wrap');

export default class MessagesList {

    constructor(channel) {
        this.channel = channel;
        this.screen = this.channel.screen;
        this.api = this.channel.api;

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

    }

    getUserReplacementMap() {
        let map = {};
        Object.values(this.api.users).forEach(u => {
            map['@' + u.id] = '@' + u.name;
        });
        return map;
    }

    init() {
        // this.channel.box.append(this.box);
        this.box.enableInput();
        this.screen.render();
        this.refresh();
        this.api.on('message', this.receiveMessage.bind(this));
    }

    receiveMessage(obj) {
        if (obj.channel === this.channel.channel.id) {
            this.messages.push(obj);
            this.render();
        }
    }

    refresh() {
        this.api.getChannelHistory(this.channel.channel, (err, resp, body) => {this.loadHistory(body)});
    }

    destroy() {
        this.box.destroy();
    }

    render() {
        let lines = [];
        const width = parseInt(this.box.width) - 15;
        const userMap = this.getUserReplacementMap();
        this.messages
                .filter(m => m.type === 'message')
                .forEach(m => {
                    const userName = this.api.getUserName(m.user);
                    let content = userName + ': ' + m.text
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
        this.messages = body.messages.reverse();
        this.render();
    }
}

module.exports = MessagesList;