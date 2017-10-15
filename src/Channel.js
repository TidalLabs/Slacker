
var blessed = require('blessed');
const MessagesList = require('./MessagesList');
const MessageForm = require('./MessageForm');

export default class ChannelBox {

    constructor(channel, screen, api) {
        this.channel = channel;
        this.screen = screen;
        this.api = api;

        this.box = blessed.box({
            parent: this.screen,
            label: channel.name,
            top: 'top',
            left: '30%',
            width: '70%',
            height: '100%',
            input: true,
            mouse: true,
            border: {
                type: 'line'
            },
            style: {
                fg: 'white',
                bg: 'black',
                border: {
                    fg: '#f0f0f0'
                }
            }
        });

        this.messagesList = new MessagesList(this);
        this.messageForm = new MessageForm(this);
    }

    refresh() {
        this.messagesList.refresh();
    }

    destroy() {
        this.messagesList.destroy();
        this.messageForm.destroy();
        this.box.destroy();
    }
}

module.exports = ChannelBox;
