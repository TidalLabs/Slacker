var blessed = require('blessed');

export default class MessageForm {

    constructor(channel) {
        this.channel = channel;
        this.screen = this.channel.screen;
        this.api = this.channel.api;

        this.form = blessed.form({
            parent: this.channel.box,
            keys: true,
            left: 0,
            bottom: 0,
            width: '100%-2',
            height: '10%',
            bg: 'black',
            // border: {type: 'line'}
        });

        this.textbox = blessed.textbox({
            parent: this.form,
            left: 0,
            top: 0,
            width: '100%',
            height: '100%',
            bg: 'black',
            fg: 'white',
            input: true,
            mouse: true,
            keys: true,
            inputOnFocus: true,
            label: 'Write Message (Ctrl-o)',
            border: {type: 'line'}
        });

        this.textbox.key('enter', (ch, key) => {
            this.form.submit();
        });

        this.form.on('submit', (data) => {
            const message = data.textbox;
            if (message.length > 0) {
                this.api.postMessage(this.channel.channel, message, (err, resp, body) => {
                    this.form.reset();
                    this.screen.render();
                    this.textbox.focus();
                });
            }

        });
    }

    destroy() {
        this.form.destroy();
        this.textbox.destroy();
    }
}

module.exports = MessageForm;