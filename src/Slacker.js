
const blessed = require('blessed');
const SlackAPI = require('./SlackAPI');
const ChannelsList = require('./ChannelsList');
const ChannelBox = require('./Channel');

export default class Slacker {

    constructor(token) {
        this.token = token;
        this.api = new SlackAPI(this.token);

        this.screen = blessed.screen({
            smartCSR: true
        });

        this.channelsList = new ChannelsList(this.screen, this.api);
        this.channel = null;
        this.channelBox = null;
    }

    changeChannel(channel) {
        this.channel = channel;

        if (this.channelBox) {
            this.channelBox.destroy();
            this.channelBox = null;
        }

        this.channelBox = new ChannelBox(this.channel, this.screen, this.api);

    }

    init() {

        this.screen.key(['escape', 'C-c'], function(ch, key) {
            return process.exit(0);
        });

        this.channelsList.on('select_channel', (ch) => {
            this.changeChannel(ch);
        });

        this.channelsList.init();
    }

}

module.exports = Slacker;